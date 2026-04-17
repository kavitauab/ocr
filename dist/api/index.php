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

// Health check — public endpoint returns only basic status/version.
// For full diagnostics pass `Authorization: Bearer <CRON_SECRET>` and get
// cron_version, last_invoice_raw, companies_fields, etc.
if (($pathParts[0] ?? '') === 'health') {
    try {
        $info = [
            'status' => 'ok',
            'version' => API_VERSION,
            'php' => PHP_VERSION,
        ];

        // Diagnostic fields only exposed when caller presents the CRON_SECRET.
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        $isDiagnostic = defined('CRON_SECRET') && CRON_SECRET !== ''
            && preg_match('/^Bearer\s+(\S+)$/', $authHeader, $m)
            && hash_equals(CRON_SECRET, $m[1]);

        if ($isDiagnostic) {
            $db = getDBConnection();
            $info['upload_dir'] = UPLOAD_DIR;
            $info['upload_dir_exists'] = is_dir(UPLOAD_DIR);
            $info['upload_dir_writable'] = is_writable(UPLOAD_DIR);
            $info['api_key_set'] = !empty(getAnthropicApiKey());
            $info['upload_max'] = ini_get('upload_max_filesize');
            $info['post_max'] = ini_get('post_max_size');
            $info['max_exec'] = ini_get('max_execution_time');
            $info['opcache_cli'] = ini_get('opcache.enable_cli');
            $info['opcache_enabled'] = function_exists('opcache_get_status')
                ? (opcache_get_status(false)['opcache_enabled'] ?? 'N/A')
                : 'no ext';
            $cronVerFile = __DIR__ . '/_cron_version.txt';
            $info['cron_version'] = file_exists($cronVerFile) ? trim(file_get_contents($cronVerFile)) : 'no cron run yet';
            $lastInv = $db->query("SELECT id, ocr_model, ocr_escalated, updated_at, invoice_number, vendor_name, subtotal_amount, tax_amount, total_amount, currency, confidence_scores FROM invoices WHERE status='completed' ORDER BY updated_at DESC LIMIT 3")->fetchAll();
            $info['last_invoices'] = array_map(function ($i) {
                $conf = json_decode($i['confidence_scores'] ?? '{}', true) ?: [];
                return [
                    'id' => $i['id'],
                    'invoice_number' => $i['invoice_number'],
                    'vendor_name' => $i['vendor_name'],
                    'subtotal_amount' => $i['subtotal_amount'],
                    'tax_amount' => $i['tax_amount'],
                    'total_amount' => $i['total_amount'],
                    'currency' => $i['currency'],
                    'confidence_tax' => $conf['taxAmount'] ?? null,
                    'confidence_subtotal' => $conf['subtotalAmount'] ?? null,
                    'confidence_total' => $conf['totalAmount'] ?? null,
                    'ocr_model' => $i['ocr_model'],
                    'ocr_escalated' => $i['ocr_escalated'],
                    'updated_at' => $i['updated_at'],
                ];
            }, $lastInv ?: []);
            $info['ocr_model_column_exists'] = (bool)$db->query("SHOW COLUMNS FROM invoices LIKE 'ocr_model'")->fetch();
        }

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
    if (($pathParts[1] ?? '') === 'migrate-schema') {
        require_once __DIR__ . '/functions/migrate_schema.php';
        exit;
    }
    if (($pathParts[1] ?? '') === 'process-ocr') {
        require_once __DIR__ . '/functions/process_ocr_queue.php';
        exit;
    }
    if (($pathParts[1] ?? '') === 'test-vecticum') {
        require_once __DIR__ . '/functions/test_vecticum.php';
        exit;
    }
    if (($pathParts[1] ?? '') === 'send-test-email') {
        require_once __DIR__ . '/functions/send_test_email.php';
        exit;
    }
    if (($pathParts[1] ?? '') === 'cleanup') {
        require_once __DIR__ . '/functions/cleanup.php';
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
$reservedActions = ['stats', 'filter', 'health', 'models', 'usage'];
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
