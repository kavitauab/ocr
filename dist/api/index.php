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
