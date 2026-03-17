<?php
require_once __DIR__ . '/config.php';

handleCORS();

// Auth check for protected endpoints
if (!isPublicEndpoint()) {
    $GLOBALS['auth_user'] = requireAuth();
}

// Parse request
$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = preg_replace('#^/api#', '', $path);
$pathParts = array_values(array_filter(explode('/', $path)));

// Migration endpoint (temporary)
if (($pathParts[0] ?? '') === 'run-migrate') {
    $db = getDBConnection();
    $results = [];

    // 1. Add ocr_sent_at / ocr_returned_at to invoices if missing
    try {
        $db->exec("ALTER TABLE invoices ADD COLUMN ocr_sent_at DATETIME NULL AFTER document_type");
        $results[] = 'Added ocr_sent_at to invoices';
    } catch (\Throwable $e) {
        $results[] = 'ocr_sent_at already exists or error: ' . $e->getMessage();
    }
    try {
        $db->exec("ALTER TABLE invoices ADD COLUMN ocr_returned_at DATETIME NULL AFTER ocr_sent_at");
        $results[] = 'Added ocr_returned_at to invoices';
    } catch (\Throwable $e) {
        $results[] = 'ocr_returned_at already exists or error: ' . $e->getMessage();
    }

    // 2. Add document_type to invoices if missing
    try {
        $db->exec("ALTER TABLE invoices ADD COLUMN document_type VARCHAR(50) DEFAULT NULL AFTER ocr_returned_at");
        $results[] = 'Added document_type to invoices';
    } catch (\Throwable $e) {
        $results[] = 'document_type already exists or error: ' . $e->getMessage();
    }

    // 3. Add token columns to usage_logs if missing
    $usageCols = [
        'ocr_jobs_count' => 'INT NOT NULL DEFAULT 0',
        'ocr_input_tokens' => 'BIGINT NOT NULL DEFAULT 0',
        'ocr_output_tokens' => 'BIGINT NOT NULL DEFAULT 0',
        'ocr_total_tokens' => 'BIGINT NOT NULL DEFAULT 0',
        'ocr_cost_usd' => 'DECIMAL(14,6) NOT NULL DEFAULT 0',
    ];
    foreach ($usageCols as $col => $def) {
        try {
            $db->exec("ALTER TABLE usage_logs ADD COLUMN $col $def");
            $results[] = "Added $col to usage_logs";
        } catch (\Throwable $e) {
            $results[] = "$col already exists in usage_logs";
        }
    }

    // 4. Create ocr_jobs table if missing
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS ocr_jobs (
            id VARCHAR(30) NOT NULL PRIMARY KEY,
            invoice_id VARCHAR(30) NOT NULL,
            company_id VARCHAR(30) NOT NULL,
            provider VARCHAR(50) NOT NULL DEFAULT 'anthropic',
            model VARCHAR(100),
            status ENUM('processing','completed','failed') NOT NULL DEFAULT 'processing',
            request_id VARCHAR(255),
            input_tokens INT NOT NULL DEFAULT 0,
            output_tokens INT NOT NULL DEFAULT 0,
            total_tokens INT NOT NULL DEFAULT 0,
            cache_creation_input_tokens INT NOT NULL DEFAULT 0,
            cache_read_input_tokens INT NOT NULL DEFAULT 0,
            cost_usd DECIMAL(14,6) NOT NULL DEFAULT 0,
            error_message TEXT,
            sent_at DATETIME NULL,
            returned_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_ocr_jobs_company_created (company_id, created_at),
            INDEX idx_ocr_jobs_invoice (invoice_id),
            INDEX idx_ocr_jobs_status (status),
            FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
            FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $results[] = 'Created ocr_jobs table (or already existed)';
    } catch (\Throwable $e) {
        $results[] = 'ocr_jobs table error: ' . $e->getMessage();
    }

    // 5. Backfill OCR timestamps for existing completed invoices
    try {
        $stmt = $db->exec("UPDATE invoices SET ocr_sent_at = created_at WHERE status = 'completed' AND ocr_sent_at IS NULL");
        $results[] = "Backfilled ocr_sent_at for completed invoices: $stmt rows";
    } catch (\Throwable $e) {
        $results[] = 'Backfill ocr_sent_at error: ' . $e->getMessage();
    }
    try {
        $stmt = $db->exec("UPDATE invoices SET ocr_returned_at = COALESCE(updated_at, created_at) WHERE status = 'completed' AND ocr_returned_at IS NULL");
        $results[] = "Backfilled ocr_returned_at for completed invoices: $stmt rows";
    } catch (\Throwable $e) {
        $results[] = 'Backfill ocr_returned_at error: ' . $e->getMessage();
    }

    // 6. Add indexes on OCR timestamp columns
    try {
        $db->exec("ALTER TABLE invoices ADD INDEX idx_ocr_sent_at (ocr_sent_at)");
        $results[] = 'Added index on ocr_sent_at';
    } catch (\Throwable $e) {
        $results[] = 'ocr_sent_at index already exists';
    }
    try {
        $db->exec("ALTER TABLE invoices ADD INDEX idx_ocr_returned_at (ocr_returned_at)");
        $results[] = 'Added index on ocr_returned_at';
    } catch (\Throwable $e) {
        $results[] = 'ocr_returned_at index already exists';
    }

    sendJSON(['results' => $results]);
}

// Health check
if (($pathParts[0] ?? '') === 'health') {
    try {
        $db = getDBConnection();
        $info = ['status' => 'ok', 'php' => PHP_VERSION, 'upload_max' => ini_get('upload_max_filesize'), 'post_max' => ini_get('post_max_size'), 'max_exec' => ini_get('max_execution_time')];
        // Check uploads dir
        $uploadDir = UPLOAD_DIR;
        $info['upload_dir'] = $uploadDir;
        $info['upload_dir_exists'] = is_dir($uploadDir);
        $info['upload_dir_writable'] = is_writable($uploadDir);
        $info['api_key_set'] = !empty(getAnthropicApiKey());
        sendJSON($info);
    } catch (\Throwable $e) {
        sendJSON(['status' => 'error', 'message' => $e->getMessage()], 500);
    }
}

// Auth routes
if (($pathParts[0] ?? '') === 'auth') {
    $action = $pathParts[1] ?? '';

    if ($action === 'login' && $method === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';

        if (!$email || !$password) sendJSON(['error' => 'Email and password required'], 400);

        $db = getDBConnection();
        $stmt = $db->prepare("SELECT * FROM users WHERE email = :email");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            $payload = [
                'id' => $user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'role' => $user['role'],
                'iat' => time(),
                'exp' => time() + 86400 * 7,
            ];
            $token = generateJWT($payload);
            unset($user['password_hash']);
            sendJSON(['token' => $token, 'user' => $user]);
        }
        sendJSON(['error' => 'Invalid credentials'], 401);
    }

    if ($action === 'me') {
        $GLOBALS['auth_user'] = requireAuth();
        sendJSON(['user' => getAuthUser()]);
    }

    if ($action === 'logout') {
        sendJSON(['success' => true]);
    }

    sendJSON(['error' => 'Not found'], 404);
}

// Cron routes
if (($pathParts[0] ?? '') === 'cron') {
    if (($pathParts[1] ?? '') === 'fetch-emails') {
        require_once __DIR__ . '/functions/fetch_emails.php';
        exit;
    }
    sendJSON(['error' => 'Not found'], 404);
}

// Special: /user/companies (singular "user")
if (($pathParts[0] ?? '') === 'user' && ($pathParts[1] ?? '') === 'companies') {
    require_once __DIR__ . '/resources/BaseResource.php';
    require_once __DIR__ . '/resources/User.php';
    $handler = new User();
    $handler->companies();
    exit;
}

// Resource routing
$resourceKey = $pathParts[0] ?? '';
$reservedActions = ['stats', 'filter'];
$id = isset($pathParts[1]) && !in_array($pathParts[1], $reservedActions) ? $pathParts[1] : null;
$action = $pathParts[2] ?? (isset($pathParts[1]) && in_array($pathParts[1], $reservedActions) ? $pathParts[1] : null);

// Map resource names
$resourceMap = [
    'invoices' => 'Invoice',
    'invoice' => 'Invoice',
    'companies' => 'Company',
    'company' => 'Company',
    'users' => 'User',
    'user' => 'User',
    'emails' => 'Email',
    'email' => 'Email',
    'settings' => 'Setting',
    'setting' => 'Setting',
];

$resourceClass = $resourceMap[$resourceKey] ?? ucfirst($resourceKey);
$resourceFile = __DIR__ . '/resources/' . $resourceClass . '.php';

if (!file_exists($resourceFile)) {
    sendJSON(['error' => 'Resource not found: ' . $resourceKey], 404);
}

require_once __DIR__ . '/resources/BaseResource.php';
require_once $resourceFile;

$handler = new $resourceClass();

// Handle hyphenated action names (e.g., test-email -> test_email, fetch-emails -> fetch_emails, audit-log -> audit)
if ($action) {
    $phpAction = str_replace('-', '_', $action);
    // Special case: audit-log -> audit
    if ($phpAction === 'audit_log') $phpAction = 'audit';

    if (method_exists($handler, $phpAction)) {
        $handler->$phpAction($id);
    } else {
        sendJSON(['error' => 'Action not found: ' . $action], 404);
    }
} elseif ($id) {
    if ($method === 'GET') {
        $handler->get($id);
    } elseif ($method === 'PUT' || $method === 'POST' || $method === 'PATCH') {
        $handler->update($id);
    } elseif ($method === 'DELETE') {
        $handler->delete($id);
    }
} else {
    if ($method === 'GET') {
        $handler->list();
    } elseif ($method === 'POST') {
        $handler->create();
    } elseif ($method === 'PATCH') {
        // PATCH without ID = settings update
        $handler->update(null);
    }
}
