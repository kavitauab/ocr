<?php
// Load .env file
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

// Database
define('DB_HOST', $_ENV['DB_HOST'] ?? 'localhost');
define('DB_NAME', $_ENV['DB_NAME'] ?? 'admin_ocr');
define('DB_USER', $_ENV['DB_USER'] ?? 'root');
define('DB_PASS', $_ENV['DB_PASS'] ?? '');
define('DB_CHARSET', 'utf8mb4');

// JWT
define('JWT_SECRET', $_ENV['JWT_SECRET'] ?? 'change-this');

// Claude API
define('ANTHROPIC_API_KEY', $_ENV['ANTHROPIC_API_KEY'] ?? '');

// File storage
define('UPLOAD_DIR', $_ENV['UPLOAD_DIR'] ?? __DIR__ . '/../uploads');

// Cron
define('CRON_SECRET', $_ENV['CRON_SECRET'] ?? '');

// CORS
$defaultCorsOrigin = isset($_SERVER['HTTP_HOST']) ? 'https://' . $_SERVER['HTTP_HOST'] : 'http://localhost:5173';
define('CORS_ORIGIN', $_ENV['CORS_ORIGIN'] ?? $defaultCorsOrigin);

// App
define('APP_ENV', $_ENV['APP_ENV'] ?? 'development');
date_default_timezone_set('Europe/Vilnius');

// Error handling
if (APP_ENV === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// Database connection (singleton)
function getDBConnection() {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}

// CORS handler
function handleCORS() {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowed = [CORS_ORIGIN, 'http://localhost:5173', 'http://localhost:4173', 'https://ocr.gentrula.lt'];

    if (in_array($origin, $allowed)) {
        header("Access-Control-Allow-Origin: $origin");
    }
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Allow-Credentials: true');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

// JSON response helper
function sendJSON($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ID generation (replacement for nanoid)
function generateId() {
    return bin2hex(random_bytes(11));
}

// Base64 URL-safe encoding
function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
function base64url_decode($data) {
    return base64_decode(strtr($data, '-_', '+/'));
}

// Generate JWT token
function generateJWT($payload) {
    $header = base64url_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $body = base64url_encode(json_encode($payload));
    $signature = base64url_encode(hash_hmac('sha256', "$header.$body", JWT_SECRET, true));
    return "$header.$body.$signature";
}

function validateJWTToken($token) {
    if (!$token) return null;
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;

    $validSig = base64url_encode(hash_hmac('sha256', "$parts[0].$parts[1]", JWT_SECRET, true));
    if (!hash_equals($validSig, $parts[2])) return null;

    $payload = json_decode(base64url_decode($parts[1]), true);
    if (!$payload || !isset($payload['exp']) || $payload['exp'] <= time()) return null;

    return $payload;
}

// Auth check - returns user payload {id, name, email, role}
function requireAuth() {
    $header = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? (function_exists('apache_request_headers') ? (apache_request_headers()['Authorization'] ?? '') : '')
        ?? '';
    if (preg_match('/Bearer\s+(.+)/', $header, $matches)) {
        $payload = validateJWTToken($matches[1]);
        if ($payload) return $payload;
    }

    // URL token fallback for file downloads
    $qsToken = $_GET['access_token'] ?? '';
    if ($qsToken !== '') {
        $payload = validateJWTToken($qsToken);
        if ($payload) return $payload;
    }

    sendJSON(['error' => 'Unauthorized'], 401);
}

function isPublicEndpoint() {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $path = preg_replace('#^/api#', '', $path);
    $publicPaths = ['/auth/login', '/health'];
    if (str_starts_with($path, '/cron/')) return true;
    return in_array($path, $publicPaths);
}

function getAuthUser() {
    return $GLOBALS['auth_user'] ?? null;
}

function requireRole($roles) {
    $user = getAuthUser();
    if (!$user) sendJSON(['error' => 'Unauthorized'], 401);

    $role = $user['role'] ?? null;
    $allowed = is_array($roles) ? $roles : [$roles];
    if (!in_array($role, $allowed, true)) {
        sendJSON(['error' => 'Forbidden'], 403);
    }
    return $user;
}

// Company access helpers
function getUserCompanies($user) {
    $db = getDBConnection();
    if ($user['role'] === 'superadmin') {
        return $db->query("SELECT c.*, 'superadmin' as company_role FROM companies c ORDER BY c.created_at DESC")->fetchAll();
    }
    $stmt = $db->prepare("SELECT c.*, uc.role as company_role FROM companies c INNER JOIN user_companies uc ON uc.company_id = c.id WHERE uc.user_id = :userId ORDER BY c.created_at DESC");
    $stmt->execute(['userId' => $user['id']]);
    return $stmt->fetchAll();
}

function getUserCompanyRole($userId, $companyId) {
    $db = getDBConnection();
    $stmt = $db->prepare("SELECT role FROM user_companies WHERE user_id = :userId AND company_id = :companyId");
    $stmt->execute(['userId' => $userId, 'companyId' => $companyId]);
    $row = $stmt->fetch();
    return $row ? $row['role'] : null;
}

function requireCompanyAccess($user, $companyId, $minRole = 'viewer') {
    if ($user['role'] === 'superadmin') return true;

    $role = getUserCompanyRole($user['id'], $companyId);
    if (!$role) sendJSON(['error' => 'Access denied'], 403);

    $hierarchy = ['viewer' => 0, 'manager' => 1, 'admin' => 2, 'owner' => 3];
    $userLevel = $hierarchy[$role] ?? -1;
    $requiredLevel = $hierarchy[$minRole] ?? 0;
    if ($userLevel < $requiredLevel) {
        sendJSON(['error' => 'Insufficient permissions'], 403);
    }
    return true;
}

// Mask secrets in company data
function maskCompanySecrets($company) {
    $secretFields = ['ms_client_secret', 'ms_access_token', 'ms_token_expires', 'vecticum_client_secret', 'vecticum_access_token', 'vecticum_token_expires'];
    foreach ($secretFields as $field) {
        if (isset($company[$field]) && $company[$field]) {
            if (in_array($field, ['ms_access_token', 'ms_token_expires', 'vecticum_access_token', 'vecticum_token_expires'])) {
                unset($company[$field]);
            } else {
                $company[$field] = '••••••••';
            }
        }
    }
    return $company;
}
