<?php
// Cron endpoint - fetch emails for all enabled companies
// Auth: CRON_SECRET bearer token (timing-safe)

verifyCronAuth();

require_once __DIR__ . '/../lib/email_processor.php';

$db = getDBConnection();
$stmt = $db->prepare("SELECT * FROM companies WHERE ms_fetch_enabled = 1");
$stmt->execute();
$enabledCompanies = $stmt->fetchAll();

$results = [];
foreach ($enabledCompanies as $company) {
    try {
        $results[$company['code']] = processCompanyEmails($company['id']);
    } catch (Exception $e) {
        $results[$company['code']] = ['error' => $e->getMessage()];
    }
}

sendJSON(['companiesProcessed' => count($enabledCompanies), 'results' => $results]);
