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
            // Optional search param to find a specific invoice by invoice_number / vendor_name / raw_extraction contents
            $diagSearch = trim($_GET['findInvoice'] ?? '');
            $diagLimit = intval($_GET['diagLimit'] ?? 3);
            $diagLimit = max(1, min(30, $diagLimit));
            if ($diagSearch !== '') {
                $stmt = $db->prepare("SELECT id, ocr_model, ocr_escalated, updated_at, invoice_number, vendor_name, subtotal_amount, tax_amount, total_amount, currency, confidence_scores, raw_extraction, vecticum_id, vecticum_error FROM invoices WHERE invoice_number LIKE :q1 OR vendor_name LIKE :q2 OR raw_extraction LIKE :q3 ORDER BY updated_at DESC LIMIT $diagLimit");
                $q = '%' . $diagSearch . '%';
                $stmt->execute(['q1' => $q, 'q2' => $q, 'q3' => $q]);
                $lastInv = $stmt->fetchAll();
            } else {
                $lastInv = $db->query("SELECT id, ocr_model, ocr_escalated, updated_at, invoice_number, vendor_name, subtotal_amount, tax_amount, total_amount, currency, confidence_scores, vecticum_id, vecticum_error FROM invoices WHERE status='completed' ORDER BY updated_at DESC LIMIT $diagLimit")->fetchAll();
            }
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
                    'vecticum_id' => $i['vecticum_id'] ?? null,
                    'vecticum_error' => $i['vecticum_error'] ?? null,
                    'ocr_model' => $i['ocr_model'],
                    'ocr_escalated' => $i['ocr_escalated'],
                    'updated_at' => $i['updated_at'],
                ];
            }, $lastInv ?: []);
            $info['ocr_model_column_exists'] = (bool)$db->query("SHOW COLUMNS FROM invoices LIKE 'ocr_model'")->fetch();

            // Show which fields each company has enabled + email-ingest summary
            // Recent emails for specific company (findCompany=<id|code|name>)
            $findCompany = trim($_GET['findCompany'] ?? '');
            if ($findCompany !== '') {
                $stmt = $db->prepare("SELECT id FROM companies WHERE id = :c1 OR code = :c2 OR name LIKE :c3 LIMIT 1");
                $stmt->execute(['c1' => $findCompany, 'c2' => $findCompany, 'c3' => '%' . $findCompany . '%']);
                $cid = $stmt->fetchColumn();
                if ($cid) {
                    $fromFilter = trim($_GET['fromFilter'] ?? '');
                    $limit = max(1, min(100, intval($_GET['limit'] ?? 20)));
                    if ($fromFilter !== '') {
                        $emailsStmt = $db->prepare("SELECT id, message_id, subject, from_email, received_date, has_attachments, attachment_count, status, created_at FROM email_inbox WHERE company_id = :cid AND from_email LIKE :ff ORDER BY received_date DESC LIMIT $limit");
                        $emailsStmt->execute(['cid' => $cid, 'ff' => '%' . $fromFilter . '%']);
                    } else {
                        $emailsStmt = $db->prepare("SELECT id, message_id, subject, from_email, received_date, has_attachments, attachment_count, status, created_at FROM email_inbox WHERE company_id = :cid ORDER BY received_date DESC LIMIT $limit");
                        $emailsStmt->execute(['cid' => $cid]);
                    }
                    $info['company_recent_emails'] = $emailsStmt->fetchAll();

                    // Also show invoice counts by status for this company
                    $iStmt = $db->prepare("SELECT status, COUNT(*) AS n FROM invoices WHERE company_id = :cid GROUP BY status");
                    $iStmt->execute(['cid' => $cid]);
                    $byStatus = [];
                    foreach ($iStmt->fetchAll() as $r) $byStatus[$r['status']] = (int)$r['n'];
                    $info['company_invoice_counts'] = $byStatus;

                    // And most recent invoices (to see if new ones landed)
                    $rStmt = $db->prepare("SELECT id, status, invoice_number, vendor_name, created_at, updated_at FROM invoices WHERE company_id = :cid ORDER BY created_at DESC LIMIT 10");
                    $rStmt->execute(['cid' => $cid]);
                    $info['company_recent_invoices'] = $rStmt->fetchAll();

                    // Active OCR jobs for this company
                    $jStmt = $db->prepare("SELECT oj.id, oj.invoice_id, oj.status, oj.attempt, oj.next_retry_at, oj.error_message, oj.updated_at FROM ocr_jobs oj WHERE oj.company_id = :cid AND oj.status IN ('queued','processing','retrying') ORDER BY oj.updated_at DESC LIMIT 20");
                    $jStmt->execute(['cid' => $cid]);
                    $info['company_active_jobs'] = $jStmt->fetchAll();
                } else {
                    $info['company_recent_emails'] = 'company not found';
                }
            }
            $info['company_extraction_fields'] = array_map(function ($c) use ($db) {
                $ef = $c['extraction_fields'];
                $parsed = is_string($ef) ? json_decode($ef, true) : $ef;
                // Count unprocessed emails
                $stmtE = $db->prepare("SELECT status, COUNT(*) AS n FROM email_inbox WHERE company_id = :cid GROUP BY status");
                $stmtE->execute(['cid' => $c['id']]);
                $byStatus = [];
                foreach ($stmtE->fetchAll() as $r) $byStatus[$r['status']] = (int)$r['n'];
                return [
                    'id' => $c['id'],
                    'code' => $c['code'],
                    'name' => $c['name'],
                    'tax_enabled' => $parsed === null ? 'ALL' : (in_array('taxAmount', $parsed ?: []) ? 'YES' : 'NO'),
                    'email_inbox_counts' => $byStatus,
                ];
            }, $db->query("SELECT id, name, code, extraction_fields FROM companies")->fetchAll() ?: []);
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
