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

if ($action === 'setup') {
    // One-time setup: configure vecticum on a company
    $baseUrl = $_GET['baseUrl'] ?? '';
    $clientId = $_GET['clientId'] ?? '';
    $clientSecret = $_GET['clientSecret'] ?? '';
    $vecCompanyId = $_GET['vecCompanyId'] ?? '';

    if (!$baseUrl || !$clientId || !$clientSecret || !$vecCompanyId) {
        sendJSON(['error' => 'Need: baseUrl, clientId, clientSecret, vecCompanyId'], 400);
    }

    $stmt = $db->prepare("UPDATE companies SET vecticum_enabled = 1, vecticum_api_base_url = :url, vecticum_client_id = :cid, vecticum_client_secret = :cs, vecticum_company_id = :vcid WHERE id = :id");
    $stmt->execute(['url' => $baseUrl, 'cid' => $clientId, 'cs' => $clientSecret, 'vcid' => $vecCompanyId, 'id' => $companyId]);
    sendJSON(['action' => 'setup', 'success' => true, 'message' => "Vecticum configured for company $companyId"]);
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
    $token = getVecticumToken($company);
    $baseUrl = $company['vecticum_api_base_url'];
    $companyEndpoint = $company['vecticum_company_id'];

    $endpoints = [
        'base' => $baseUrl . '/' . $companyEndpoint,
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

if ($action === 'inspect-record') {
    // Get a single Vecticum record to see its full structure including files/children
    $token = getVecticumToken($company);
    $baseUrl = $company['vecticum_api_base_url'];
    $companyEndpoint = $company['vecticum_company_id'];
    $recordId = $_GET['recordId'] ?? '';

    // If no recordId, get the first record from the collection
    if (!$recordId) {
        $ch = curl_init($baseUrl . '/' . $companyEndpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json', "Authorization: Bearer $token"],
            CURLOPT_TIMEOUT => 10,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        $list = json_decode($response, true);
        // Find one that has additionalFiles
        foreach ($list as $item) {
            if (!empty($item['additionalFiles'])) {
                $recordId = $item['id'];
                break;
            }
        }
        if (!$recordId && !empty($list[0]['id'])) $recordId = $list[0]['id'];
    }

    if (!$recordId) sendJSON(['error' => 'No records found'], 404);

    // Try GET on the specific record
    $ch = curl_init($baseUrl . '/' . $companyEndpoint . '/' . $recordId);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json', "Authorization: Bearer $token"],
        CURLOPT_TIMEOUT => 10,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $record = json_decode($response, true);

    // Also try to discover child/file endpoints
    $probes = [
        'files' => $baseUrl . '/' . $companyEndpoint . '/' . $recordId . '/files',
        'attachments' => $baseUrl . '/' . $companyEndpoint . '/' . $recordId . '/attachments',
        'children' => $baseUrl . '/' . $companyEndpoint . '/' . $recordId . '/children',
        'documents' => $baseUrl . '/' . $companyEndpoint . '/' . $recordId . '/documents',
    ];

    $probeResults = [];
    foreach ($probes as $name => $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json', "Authorization: Bearer $token"],
            CURLOPT_TIMEOUT => 5,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $probeResults[$name] = ['httpCode' => $code, 'response' => substr($resp, 0, 500)];
    }

    sendJSON([
        'action' => 'inspect-record',
        'recordId' => $recordId,
        'recordHttpCode' => $httpCode,
        'record' => $record,
        'childEndpointProbes' => $probeResults,
    ]);
}

if ($action === 'find-with-files') {
    // Search through records to find ones with additionalFiles populated
    $token = getVecticumToken($company);
    $baseUrl = $company['vecticum_api_base_url'];
    $companyEndpoint = $company['vecticum_company_id'];

    $ch = curl_init($baseUrl . '/' . $companyEndpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json', "Authorization: Bearer $token"],
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    $list = json_decode($response, true);

    $withFiles = [];
    $fileFieldSamples = [];
    if (is_array($list)) {
        foreach ($list as $item) {
            if (!empty($item['additionalFiles'])) {
                $withFiles[] = [
                    'id' => $item['id'],
                    'invoiceNo' => $item['invoiceNo'] ?? '?',
                    'additionalFiles' => $item['additionalFiles'],
                ];
                if (count($withFiles) >= 5) break;
            }
        }
    }

    sendJSON([
        'action' => 'find-with-files',
        'totalRecords' => is_array($list) ? count($list) : 0,
        'recordsWithFiles' => count($withFiles),
        'samples' => $withFiles,
    ]);
}

sendJSON(['error' => 'Unknown action. Use: test-connection, list-invoices, send-test, send-real, probe-endpoints'], 400);
