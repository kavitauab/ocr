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

if ($action === 'try-file-upload') {
    // Try to upload a file to Vecticum's Firebase Storage and update the record
    $token = getVecticumToken($company);
    $baseUrl = $company['vecticum_api_base_url'];
    $companyEndpoint = $company['vecticum_company_id'];
    $recordId = $_GET['recordId'] ?? '';
    $invoiceId = $_GET['invoiceId'] ?? '';

    if (!$recordId || !$invoiceId) sendJSON(['error' => 'Need recordId (vecticum) and invoiceId (our system)'], 400);

    // Get our invoice to find the file
    $stmt = $db->prepare("SELECT * FROM invoices WHERE id = :id");
    $stmt->execute(['id' => $invoiceId]);
    $invoice = $stmt->fetch();
    if (!$invoice) sendJSON(['error' => 'Invoice not found'], 404);

    $filePath = rtrim(UPLOAD_DIR, '/') . '/' . $invoice['stored_filename'];
    if (!file_exists($filePath)) sendJSON(['error' => 'File not found: ' . $filePath], 404);

    $fileContent = file_get_contents($filePath);
    $fileName = $invoice['original_filename'];
    $fileType = $invoice['file_type'] ?? 'application/pdf';
    $fileSize = filesize($filePath);

    // Firebase Storage upload path: {companyId}/{objectTypeId}/{recordId}/{randomId}/{filename}
    $vecticumCompanyId = 'TKZ3DS7QjhRWkzKNNg0C';
    $objectTypeId = 'Z9OVQWEWH7bYmO1ydt7O'; // Invoice object type
    $randomId = substr(str_shuffle('abcdefghijklmnopqrstuvwxyz0123456789'), 0, 8);
    $storagePath = "$vecticumCompanyId/$objectTypeId/$recordId/$randomId/$fileName";

    // Try uploading to Firebase Storage
    $bucket = 'vecticum-prod-eu.appspot.com';
    $encodedPath = rawurlencode($storagePath);
    $uploadUrl = "https://firebasestorage.googleapis.com/v0/b/$bucket/o/$encodedPath";

    $ch = curl_init($uploadUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $fileContent,
        CURLOPT_HTTPHEADER => [
            "Content-Type: $fileType",
            "Authorization: Bearer $token",
        ],
        CURLOPT_TIMEOUT => 60,
    ]);
    $uploadResponse = curl_exec($ch);
    $uploadCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $uploadData = json_decode($uploadResponse, true);

    // If upload succeeded, try to update the record with file reference
    $results = [
        'firebaseUpload' => [
            'httpCode' => $uploadCode,
            'path' => $storagePath,
            'response' => $uploadData ?? substr($uploadResponse, 0, 500),
        ],
    ];

    if ($uploadCode === 200) {
        // Get download token from upload response
        $downloadToken = $uploadData['downloadTokens'] ?? '';
        $downloadURL = "https://firebasestorage.googleapis.com/v0/b/$bucket/o/$encodedPath?alt=media" . ($downloadToken ? "&token=$downloadToken" : '');

        $filesPayload = [
            [
                'path' => $storagePath,
                'name' => $fileName,
                'downloadURL' => $downloadURL,
                'type' => $fileType,
                'size' => (string)$fileSize,
            ]
        ];

        // Try PATCH to update the record with file reference
        $ch = curl_init($baseUrl . '/' . $companyEndpoint . '/' . $recordId);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'PATCH',
            CURLOPT_POSTFIELDS => json_encode(['files' => $filesPayload]),
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                "Authorization: Bearer $token",
            ],
            CURLOPT_TIMEOUT => 15,
        ]);
        $patchResponse = curl_exec($ch);
        $patchCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $results['patchRecord'] = [
            'httpCode' => $patchCode,
            'response' => json_decode($patchResponse, true) ?? substr($patchResponse, 0, 500),
        ];

        // If PATCH didn't work, try PUT
        if ($patchCode >= 400) {
            $ch = curl_init($baseUrl . '/' . $companyEndpoint . '/' . $recordId);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => 'PUT',
                CURLOPT_POSTFIELDS => json_encode(['files' => $filesPayload]),
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'Content-Type: application/json',
                    "Authorization: Bearer $token",
                ],
                CURLOPT_TIMEOUT => 15,
            ]);
            $putResponse = curl_exec($ch);
            $putCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $results['putRecord'] = [
                'httpCode' => $putCode,
                'response' => json_decode($putResponse, true) ?? substr($putResponse, 0, 500),
            ];
        }
    }

    sendJSON(['action' => 'try-file-upload', 'results' => $results]);
}

if ($action === 'try-patch-files') {
    // Try to PATCH just the files field on a record to see if the API accepts it
    $token = getVecticumToken($company);
    $baseUrl = $company['vecticum_api_base_url'];
    $companyEndpoint = $company['vecticum_company_id'];
    $recordId = $_GET['recordId'] ?? '';
    if (!$recordId) sendJSON(['error' => 'Need recordId'], 400);

    // Try different HTTP methods to update
    $methods = ['PATCH', 'PUT'];
    $results = [];

    foreach ($methods as $method) {
        // Try updating with just the description to see if updates work at all
        $ch = curl_init($baseUrl . '/' . $companyEndpoint . '/' . $recordId);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => json_encode(['additionalInfo' => 'Test update from OCR system']),
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                "Authorization: Bearer $token",
            ],
            CURLOPT_TIMEOUT => 10,
        ]);
        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $results[$method] = ['httpCode' => $code, 'response' => json_decode($response, true) ?? substr($response, 0, 500)];
    }

    sendJSON(['action' => 'try-patch-files', 'results' => $results]);
}

if ($action === 'try-multipart') {
    // Try multipart/form-data upload directly to the API endpoint
    $token = getVecticumToken($company);
    $baseUrl = $company['vecticum_api_base_url'];
    $companyEndpoint = $company['vecticum_company_id'];
    $recordId = $_GET['recordId'] ?? '';
    $invoiceId = $_GET['invoiceId'] ?? '';

    if (!$recordId || !$invoiceId) sendJSON(['error' => 'Need recordId and invoiceId'], 400);

    $stmt = $db->prepare("SELECT * FROM invoices WHERE id = :id");
    $stmt->execute(['id' => $invoiceId]);
    $invoice = $stmt->fetch();
    if (!$invoice) sendJSON(['error' => 'Invoice not found'], 404);

    $filePath = rtrim(UPLOAD_DIR, '/') . '/' . $invoice['stored_filename'];
    if (!file_exists($filePath)) sendJSON(['error' => "File not found: $filePath"], 404);

    $fileName = $invoice['original_filename'];
    $fileType = $invoice['file_type'] ?? 'application/pdf';

    // Try multipart upload directly to the record endpoint
    $cfile = new CURLFile($filePath, $fileType, $fileName);

    $ch = curl_init($baseUrl . '/' . $companyEndpoint . '/' . $recordId);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_POSTFIELDS => ['file' => $cfile],
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            "Authorization: Bearer $token",
        ],
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $results = ['multipart_put' => ['httpCode' => $code, 'response' => json_decode($response, true) ?? substr($response, 0, 500)]];

    // Also try POST to the record
    $ch = curl_init($baseUrl . '/' . $companyEndpoint . '/' . $recordId);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => ['file' => $cfile],
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            "Authorization: Bearer $token",
        ],
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $results['multipart_post'] = ['httpCode' => $code, 'response' => json_decode($response, true) ?? substr($response, 0, 500)];

    // Try a /upload sub-endpoint
    $ch = curl_init($baseUrl . '/' . $companyEndpoint . '/' . $recordId . '/upload');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => ['file' => $cfile],
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            "Authorization: Bearer $token",
        ],
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $results['multipart_upload_endpoint'] = ['httpCode' => $code, 'response' => json_decode($response, true) ?? substr($response, 0, 500)];

    sendJSON(['action' => 'try-multipart', 'results' => $results]);
}

if ($action === 'full-send') {
    // Full 3-step flow: POST metadata → PUT file → PATCH metadata back
    $token = getVecticumToken($company);
    $baseUrl = $company['vecticum_api_base_url'];
    $companyEndpoint = $company['vecticum_company_id'];
    $invoiceId = $_GET['invoiceId'] ?? '';
    if (!$invoiceId) sendJSON(['error' => 'Need invoiceId'], 400);

    $stmt = $db->prepare("SELECT * FROM invoices WHERE id = :id");
    $stmt->execute(['id' => $invoiceId]);
    $invoice = $stmt->fetch();
    if (!$invoice) sendJSON(['error' => 'Invoice not found'], 404);

    $filePath = rtrim(UPLOAD_DIR, '/') . '/' . $invoice['stored_filename'];
    if (!file_exists($filePath)) sendJSON(['error' => "File not found: $filePath"], 404);

    $steps = [];

    // STEP 1: POST metadata to create record
    $total = floatval($invoice['total_amount'] ?? 0);
    $tax = floatval($invoice['tax_amount'] ?? 0);
    $subtotal = floatval($invoice['subtotal_amount'] ?? 0);
    $totalInclVat = number_format($total && $tax ? $total + $tax : $total, 2, '.', '');

    $body = array_filter([
        'invoiceNo' => $invoice['invoice_number'],
        'invoiceDate' => $invoice['invoice_date'],
        'paymentDate' => $invoice['due_date'],
        'invoiceAmount' => $subtotal ?: $total,
        'vatAmount' => $tax,
        'totalAmount' => $subtotal ?: $total,
        'totalInclVat' => $totalInclVat,
        'description' => $invoice['vendor_name'],
        'counterpartyCode' => $invoice['vendor_vat_id'],
        'currency' => ['id' => 'O18j5zeck1yHYb5W4H86', 'name' => 'EUR'],
    ], fn($v) => $v !== null && $v !== '');

    if (!empty($company['vecticum_author_id'])) {
        $body['author'] = ['id' => $company['vecticum_author_id'], 'name' => $company['vecticum_author_name'] ?? ''];
    }

    $ch = curl_init($baseUrl . '/' . $companyEndpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json', "Authorization: Bearer $token"],
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($response, true);

    $steps['1_create'] = ['httpCode' => $code, 'recordId' => $data['id'] ?? null];
    if ($code >= 300 || empty($data['id'])) {
        sendJSON(['action' => 'full-send', 'error' => 'Step 1 failed', 'steps' => $steps, 'response' => $data]);
    }

    $recordId = $data['id'];

    // STEP 2: PUT multipart to upload file
    $cfile = new CURLFile($filePath, $invoice['file_type'] ?? 'application/pdf', $invoice['original_filename']);
    $ch = curl_init($baseUrl . '/' . $companyEndpoint . '/' . $recordId);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_POSTFIELDS => ['file' => $cfile],
        CURLOPT_HTTPHEADER => ['Accept: application/json', "Authorization: Bearer $token"],
        CURLOPT_TIMEOUT => 60,
    ]);
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $steps['2_upload'] = ['httpCode' => $code, 'response' => substr($response, 0, 200)];

    // STEP 3: PATCH metadata back (since PUT wipes it)
    $ch = curl_init($baseUrl . '/' . $companyEndpoint . '/' . $recordId);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'PATCH',
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json', "Authorization: Bearer $token"],
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $steps['3_patch'] = ['httpCode' => $code];

    // STEP 4: Verify the final state
    $ch = curl_init($baseUrl . '/' . $companyEndpoint . '/' . $recordId);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json', "Authorization: Bearer $token"],
        CURLOPT_TIMEOUT => 10,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    $final = json_decode($response, true);

    $steps['4_verify'] = [
        'invoiceNo' => $final['invoiceNo'] ?? null,
        'description' => $final['description'] ?? null,
        'invoiceAmount' => $final['invoiceAmount'] ?? null,
        'files' => $final['files'] ?? null,
    ];

    sendJSON(['action' => 'full-send', 'success' => true, 'recordId' => $recordId, 'steps' => $steps]);
}

if ($action === 'test-file-upload') {
    // Test the official /files/ endpoint
    try {

    if (isset($_GET['debug'])) {
        sendJSON(['debug' => 'entered test-file-upload block']);
    }

    $token = getVecticumToken($company);
    $baseUrl = $company['vecticum_api_base_url'];
    $companyEndpoint = $company['vecticum_company_id'];
    $invoiceId = $_GET['invoiceId'] ?? '';
    $recordId = $_GET['recordId'] ?? '';
    if (!$invoiceId) sendJSON(['error' => 'Need invoiceId'], 400);

    $stmt = $db->prepare("SELECT * FROM invoices WHERE id = :id");
    $stmt->execute(['id' => $invoiceId]);
    $invoice = $stmt->fetch();
    if (!$invoice) sendJSON(['error' => 'Invoice not found'], 404);

    // Debug: show what we have
    $storedFilename = $invoice['stored_filename'] ?? 'NULL';
    $companyIdDir = $invoice['company_id'] ?? 'NULL';

    // Resolve file path - try both with and without company subdirectory
    $uploadDir = rtrim(UPLOAD_DIR, '/');
    $filePath = $uploadDir . '/' . $companyIdDir . '/' . $storedFilename;
    if (!file_exists($filePath)) {
        $filePath = $uploadDir . '/' . $storedFilename;
    }
    if (!file_exists($filePath)) sendJSON(['error' => "File not found", 'tried' => [$uploadDir . '/' . $companyIdDir . '/' . $storedFilename, $filePath], 'uploadDir' => $uploadDir], 404);

    // Debug: return file info before attempting upload
    if (isset($_GET['debug'])) {
        sendJSON(['debug' => true, 'filePath' => $filePath, 'exists' => file_exists($filePath), 'size' => filesize($filePath), 'stored' => $storedFilename, 'companyDir' => $companyIdDir, 'uploadDir' => $uploadDir]);
    }

    $mimeType = function_exists('mime_content_type') ? (mime_content_type($filePath) ?: 'application/octet-stream') : ($invoice['file_type'] ?? 'application/pdf');
    $fileName = $invoice['original_filename'];

    $steps = [];

    // If no recordId, create one first
    if (!$recordId) {
        $total = floatval($invoice['total_amount'] ?? 0);
        $tax = floatval($invoice['tax_amount'] ?? 0);
        $subtotal = floatval($invoice['subtotal_amount'] ?? 0);
        $totalInclVat = number_format($total && $tax ? $total + $tax : $total, 2, '.', '');

        $body = array_filter([
            'invoiceNo' => $invoice['invoice_number'],
            'invoiceDate' => $invoice['invoice_date'],
            'paymentDate' => $invoice['due_date'],
            'invoiceAmount' => $subtotal ?: $total,
            'vatAmount' => $tax,
            'totalAmount' => $subtotal ?: $total,
            'totalInclVat' => $totalInclVat,
            'description' => $invoice['vendor_name'],
            'counterpartyCode' => $invoice['vendor_vat_id'],
            'currency' => ['id' => 'O18j5zeck1yHYb5W4H86', 'name' => 'EUR'],
        ], fn($v) => $v !== null && $v !== '');

        if (!empty($company['vecticum_author_id'])) {
            $body['author'] = ['id' => $company['vecticum_author_id'], 'name' => $company['vecticum_author_name'] ?? ''];
        }

        $ch = curl_init($baseUrl . '/' . $companyEndpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json', "Authorization: Bearer $token"],
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = json_decode($response, true);
        $recordId = $data['id'] ?? null;
        $steps['1_create'] = ['httpCode' => $code, 'recordId' => $recordId];
        if (!$recordId) sendJSON(['error' => 'Failed to create record', 'steps' => $steps, 'response' => $data]);
    } else {
        $steps['1_create'] = ['skipped' => true, 'recordId' => $recordId];
    }

    // STEP 2: Upload file via /files/{classId}/{documentId}/files
    // Try multiple approaches
    $results = [];

    // Upload via multipart with filename as field name
    $url = $baseUrl . '/files/' . $companyEndpoint . '/' . $recordId . '/files';
    $cfile = new \CURLFile($filePath, $mimeType, $fileName);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [$fileName => $cfile],
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            "Authorization: Bearer $token",
        ],
        CURLOPT_TIMEOUT => 60,
    ]);
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $steps['2_upload'] = ['url' => $url, 'httpCode' => $code, 'success' => $code === 201, 'response' => json_decode($response, true) ?? substr($response, 0, 300)];

    // STEP 3: Verify — GET the record to check files[]
    $ch = curl_init($baseUrl . '/' . $companyEndpoint . '/' . $recordId);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json', "Authorization: Bearer $token"],
        CURLOPT_TIMEOUT => 10,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    $final = json_decode($response, true);

    $steps['3_verify'] = [
        'invoiceNo' => $final['invoiceNo'] ?? null,
        'description' => $final['description'] ?? null,
        'files' => $final['files'] ?? null,
    ];

    sendJSON(['action' => 'test-file-upload', 'success' => $code === 201, 'recordId' => $recordId, 'steps' => $steps]);
    } catch (\Throwable $e) {
        sendJSON(['error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], 500);
    }
}

sendJSON(['error' => 'Unknown action'], 400);
