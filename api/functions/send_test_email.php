<?php
// Cron endpoint - send a one-off Microsoft Graph test email.
// Auth: CRON_SECRET bearer token
// Call: POST /api/cron/send-test-email?to=email@example.com[&companyId=...]

$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if (CRON_SECRET && !preg_match('/Bearer\s+' . preg_quote(CRON_SECRET, '/') . '/', $authHeader)) {
    sendJSON(['error' => 'Unauthorized'], 401);
}

require_once __DIR__ . '/../lib/microsoft_graph.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJSON(['error' => 'Method not allowed'], 405);
}

$to = trim((string)($_GET['to'] ?? ''));
if ($to === '') {
    sendJSON(['error' => 'Recipient email is required'], 400);
}
$forceRefresh = ($_GET['forceRefresh'] ?? '1') !== '0';

$companyId = trim((string)($_GET['companyId'] ?? ''));
$db = getDBConnection();

if ($companyId !== '') {
    $stmt = $db->prepare("SELECT * FROM companies WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $companyId]);
    $companies = $stmt->fetchAll();
} else {
    $stmt = $db->query("SELECT * FROM companies WHERE ms_sender_email IS NOT NULL AND ms_sender_email <> '' AND ms_fetch_enabled = 1 ORDER BY updated_at DESC, created_at DESC");
    $companies = $stmt->fetchAll();
}

if (empty($companies)) {
    sendJSON(['error' => 'No company with Microsoft 365 sender email configured'], 404);
}

$subject = 'Graph test email from OCR';
$errors = [];

foreach ($companies as $company) {
    $body = "This is a test email sent through Microsoft Graph from the OCR system.\n\nTime: " . date('Y-m-d H:i:s') . "\nCompany: " . ($company['name'] ?? 'Unknown');

    try {
        sendMail($company, $to, $subject, $body, 'Text', $forceRefresh);
        sendJSON([
            'success' => true,
            'message' => 'Test email sent',
            'to' => $to,
            'companyId' => $company['id'] ?? null,
            'companyName' => $company['name'] ?? null,
            'senderEmail' => $company['ms_sender_email'] ?? null,
            'subject' => $subject,
            'forceRefresh' => $forceRefresh,
            'attempted' => count($errors) + 1,
            'previousErrors' => $errors,
        ]);
    } catch (\Throwable $e) {
        $errors[] = [
            'companyId' => $company['id'] ?? null,
            'companyName' => $company['name'] ?? null,
            'senderEmail' => $company['ms_sender_email'] ?? null,
            'error' => $e->getMessage(),
        ];
    }
}

sendJSON([
    'error' => 'Failed to send email from all configured company mailboxes',
    'to' => $to,
    'errors' => $errors,
], 500);
