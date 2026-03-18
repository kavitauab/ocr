<?php
// Auth: CRON_SECRET bearer token
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (CRON_SECRET && !preg_match('/Bearer\s+' . preg_quote(CRON_SECRET, '/') . '/', $authHeader)) {
    sendJSON(['error' => 'Unauthorized'], 401);
}

require_once __DIR__ . '/../lib/vecticum.php';

$db = getDBConnection();

// Get the company with vecticum enabled
$companyId = $_GET['companyId'] ?? '';
$action = $_GET['action'] ?? 'test-connection'; // test-connection, list-invoices, send-test, probe-endpoints

if (!$companyId) {
    // List ALL companies
    $stmt = $db->query("SELECT id, name, vecticum_enabled, vecticum_api_base_url, vecticum_company_id FROM companies");
    $companies = $stmt->fetchAll();
    sendJSON(['action' => 'list-all', 'companies' => $companies]);
}

$stmt = $db->prepare("SELECT * FROM companies WHERE id = :id");
$stmt->execute(['id' => $companyId]);
$company = $stmt->fetch();
if (!$company) sendJSON(['error' => 'Company not found'], 404);

if ($action === 'test-connection') {
    $result = testVecticumConnection($company);
    sendJSON(['action' => 'test-connection', 'company' => $company['name'], 'result' => $result]);
}

if ($action === 'list-invoices') {
    // Get completed invoices for this company
    $stmt = $db->prepare("SELECT id, invoice_number, vendor_name, total_amount, currency, status FROM invoices WHERE company_id = :cid AND status = 'completed' LIMIT 5");
    $stmt->execute(['cid' => $companyId]);
    $invoices = $stmt->fetchAll();
    sendJSON(['action' => 'list-invoices', 'company' => $company['name'], 'invoices' => $invoices]);
}

if ($action === 'send-test') {
    $invoiceId = $_GET['invoiceId'] ?? '';
    if (!$invoiceId) sendJSON(['error' => 'invoiceId required'], 400);

    $stmt = $db->prepare("SELECT * FROM invoices WHERE id = :id AND company_id = :cid");
    $stmt->execute(['id' => $invoiceId, 'cid' => $companyId]);
    $invoice = $stmt->fetch();
    if (!$invoice) sendJSON(['error' => 'Invoice not found in this company'], 404);

    $metadata = [
        'invoiceNumber' => $invoice['invoice_number'],
        'invoiceDate' => $invoice['invoice_date'],
        'dueDate' => $invoice['due_date'],
        'vendorName' => $invoice['vendor_name'],
        'vendorVatId' => $invoice['vendor_vat_id'],
        'subtotalAmount' => $invoice['subtotal_amount'],
        'taxAmount' => $invoice['tax_amount'],
        'totalAmount' => $invoice['total_amount'],
        'currency' => $invoice['currency'],
    ];

    sendJSON([
        'action' => 'send-test',
        'dryRun' => false,
        'metadata_sent' => $metadata,
        'note' => 'About to send to Vecticum...',
    ]);
}

if ($action === 'send-real') {
    $invoiceId = $_GET['invoiceId'] ?? '';
    if (!$invoiceId) sendJSON(['error' => 'invoiceId required'], 400);

    $stmt = $db->prepare("SELECT * FROM invoices WHERE id = :id AND company_id = :cid");
    $stmt->execute(['id' => $invoiceId, 'cid' => $companyId]);
    $invoice = $stmt->fetch();
    if (!$invoice) sendJSON(['error' => 'Invoice not found'], 404);

    $result = uploadToVecticum($company, [
        'invoiceNumber' => $invoice['invoice_number'],
        'invoiceDate' => $invoice['invoice_date'],
        'dueDate' => $invoice['due_date'],
        'vendorName' => $invoice['vendor_name'],
        'vendorVatId' => $invoice['vendor_vat_id'],
        'subtotalAmount' => $invoice['subtotal_amount'],
        'taxAmount' => $invoice['tax_amount'],
        'totalAmount' => $invoice['total_amount'],
        'currency' => $invoice['currency'],
    ]);

    sendJSON([
        'action' => 'send-real',
        'invoice' => $invoice['invoice_number'],
        'result' => $result,
    ]);
}

if ($action === 'probe-endpoints') {
    // Try to discover what endpoints exist on the Vecticum API
    $token = getVecticumToken($company);
    $baseUrl = $company['vecticum_api_base_url'];
    $companyEndpoint = $company['vecticum_company_id'];

    $endpoints = [
        'base' => $baseUrl . '/' . $companyEndpoint,
        'base_get_first' => $baseUrl . '/' . $companyEndpoint . '?limit=1',
    ];

    $results = [];
    foreach ($endpoints as $name => $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json', "Authorization: Bearer $token"],
            CURLOPT_TIMEOUT => 10,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);
        $results[$name] = [
            'url' => $url,
            'httpCode' => $httpCode,
            'responseType' => gettype($data),
            'responseKeys' => is_array($data) ? (isset($data[0]) ? array_keys($data[0]) : array_keys($data)) : null,
            'count' => is_array($data) ? count($data) : null,
            'sample' => is_array($data) && isset($data[0]) ? $data[0] : (is_array($data) ? array_slice($data, 0, 3, true) : substr($response, 0, 500)),
        ];
    }

    sendJSON(['action' => 'probe-endpoints', 'results' => $results]);
}

sendJSON(['error' => 'Unknown action. Use: test-connection, list-invoices, send-test, send-real, probe-endpoints'], 400);
