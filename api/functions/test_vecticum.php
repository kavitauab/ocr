<?php
// Auth: CRON_SECRET bearer token (timing-safe)
verifyCronAuth();

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

if ($action === 'debug-auth') {
    // Show full debug info for vecticum auth attempt
    $info = [
        'company_name' => $company['name'],
        'has_base_url' => !empty($company['vecticum_api_base_url']),
        'base_url' => $company['vecticum_api_base_url'] ?? null,
        'has_client_id' => !empty($company['vecticum_client_id']),
        'client_id_len' => strlen($company['vecticum_client_id'] ?? ''),
        'has_client_secret' => !empty($company['vecticum_client_secret']),
        'client_secret_len' => strlen($company['vecticum_client_secret'] ?? ''),
        'has_company_id' => !empty($company['vecticum_company_id']),
        'company_id_value' => $company['vecticum_company_id'] ?? null,
        'partner_endpoint' => $company['vecticum_partner_endpoint'] ?? null,
        'class_id' => $company['vecticum_class_id'] ?? null,
        'author_id' => $company['vecticum_author_id'] ?? null,
        'author_name' => $company['vecticum_author_name'] ?? null,
        'cached_token_present' => !empty($company['vecticum_access_token']),
        'cached_token_expires' => $company['vecticum_token_expires'] ?? null,
    ];

    if (empty($company['vecticum_client_id']) || empty($company['vecticum_client_secret'])) {
        $info['result'] = 'MISSING CREDENTIALS';
        sendJSON(['action' => 'debug-auth', 'info' => $info]);
    }

    // Try fresh OAuth call directly
    $authBody = json_encode(['client_id' => $company['vecticum_client_id'], 'client_secret' => $company['vecticum_client_secret']]);
    $ch = curl_init($company['vecticum_api_base_url'] . '/oauth/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: ' . $authBody,
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_VERBOSE => true,
    ]);
    $stderr = fopen('php://temp', 'w+');
    curl_setopt($ch, CURLOPT_STDERR, $stderr);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);
    rewind($stderr);
    $verboseLog = stream_get_contents($stderr);
    fclose($stderr);

    $info['oauth_http_code'] = $httpCode;
    $info['oauth_curl_error'] = $curlErr;
    $info['oauth_response'] = substr($response ?? '', 0, 500);
    $info['oauth_response_decoded'] = json_decode($response, true);
    $info['oauth_verbose'] = substr($verboseLog, 0, 1500);

    sendJSON(['action' => 'debug-auth', 'info' => $info]);
}

if ($action === 'clear-all-invoices') {
    // Delete all invoices, ocr_jobs, email_inbox, reset usage_logs
    $counts = [];
    $counts['ocr_jobs'] = $db->exec("DELETE FROM ocr_jobs");
    $counts['invoices'] = $db->exec("DELETE FROM invoices");
    $counts['email_inbox'] = $db->exec("DELETE FROM email_inbox");
    $counts['usage_logs'] = $db->exec("UPDATE usage_logs SET invoices_processed = 0, api_calls_count = 0, ocr_jobs_count = 0, ocr_input_tokens = 0, ocr_output_tokens = 0, ocr_total_tokens = 0, ocr_cost_usd = 0, storage_used_bytes = 0");
    sendJSON(['action' => 'clear-all-invoices', 'deleted' => $counts]);
}

if ($action === 'reprocess-email') {
    $emailId = $_GET['emailId'] ?? '';
    if (!$emailId) sendJSON(['error' => 'Need emailId'], 400);
    $stmt = $db->prepare("SELECT * FROM email_inbox WHERE id = :id");
    $stmt->execute(['id' => $emailId]);
    $email = $stmt->fetch();
    if (!$email) sendJSON(['error' => 'Email not found'], 404);

    $cStmt = $db->prepare("SELECT * FROM companies WHERE id = :id");
    $cStmt->execute(['id' => $email['company_id']]);
    $emailCompany = $cStmt->fetch();

    require_once __DIR__ . '/../lib/microsoft_graph.php';
    require_once __DIR__ . '/../lib/file_storage.php';
    require_once __DIR__ . '/../lib/claude.php';
    require_once __DIR__ . '/../lib/usage.php';

    $attachments = fetchAttachments($emailCompany, $email['message_id']);
    $ALLOWED = ['application/pdf', 'image/png', 'image/jpeg', 'image/jpg'];

    // PASS 1: Save and classify all attachments
    $classified = [];
    foreach ($attachments as $a) {
        $contentType = strtolower($a['contentType'] ?? '');
        $fileName = $a['name'] ?? 'attachment';
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $typeOk = in_array($contentType, $ALLOWED)
            || ($contentType === 'application/octet-stream' && in_array($ext, ['pdf', 'png', 'jpg', 'jpeg']));
        if (!$typeOk || !empty($a['isInline']) || empty($a['contentBytes'])) continue;

        $buffer = base64_decode($a['contentBytes']);
        $saved = saveFile($buffer, $fileName, $email['company_id']);
        $filePath = getFilePath($saved['storedFilename']);

        $classification = classifyDocument($filePath, $saved['fileType']);
        if (!empty($classification['usage'])) {
            trackApiCall($email['company_id'], $classification['usage']);
        }
        $classified[] = [
            'attachment' => $a, 'saved' => $saved, 'buffer' => $buffer,
            'filePath' => $filePath, 'classification' => $classification,
        ];
    }

    // PASS 2: Group into invoice-types and other-types
    $invoiceDocs = [];
    $otherDocs = [];
    foreach ($classified as $item) {
        if (isInvoiceCategory($item['classification']['category'])) {
            $invoiceDocs[] = $item;
        } else {
            $otherDocs[] = $item;
        }
    }

    // Create invoice records for invoice-type docs (queued for OCR)
    $results = [];
    $createdInvoiceIds = [];
    foreach ($invoiceDocs as $item) {
        $invoiceId = generateId();
        $db->prepare("INSERT INTO invoices (id, company_id, email_inbox_id, source, original_filename, stored_filename, file_type, file_size, status) VALUES (:id, :cid, :eid, 'email', :fn, :sf, :ft, :fs, 'queued')")
            ->execute([
                'id' => $invoiceId, 'cid' => $email['company_id'], 'eid' => $emailId,
                'fn' => $item['attachment']['name'], 'sf' => $item['saved']['storedFilename'],
                'ft' => $item['saved']['fileType'], 'fs' => $item['attachment']['size'] ?? strlen($item['buffer']),
            ]);
        $jobId = generateId();
        $db->prepare("INSERT INTO ocr_jobs (id, invoice_id, company_id, provider, model, status, queued_at, attempt, max_attempts) VALUES (:id, :iid, :cid, 'anthropic', 'claude-sonnet-4-20250514', 'queued', NOW(), 1, 3)")
            ->execute(['id' => $jobId, 'iid' => $invoiceId, 'cid' => $email['company_id']]);
        $createdInvoiceIds[] = $invoiceId;
        $results[] = ['invoiceId' => $invoiceId, 'fileName' => $item['attachment']['name'], 'category' => $item['classification']['category']];
    }

    // Handle non-invoice docs
    if (!empty($otherDocs)) {
        if (!empty($createdInvoiceIds)) {
            // Link as additional files to first invoice
            $additionalFiles = [];
            foreach ($otherDocs as $item) {
                $additionalFiles[] = [
                    'filename' => $item['attachment']['name'],
                    'storedFilename' => $item['saved']['storedFilename'],
                    'fileType' => $item['saved']['fileType'],
                    'fileSize' => $item['attachment']['size'] ?? strlen($item['buffer']),
                    'documentType' => $item['classification']['category'],
                    'documentDetail' => $item['classification']['detail'],
                ];
                $results[] = ['fileName' => $item['attachment']['name'], 'category' => $item['classification']['category'], 'linkedTo' => $createdInvoiceIds[0]];
            }
            $db->prepare("UPDATE invoices SET additional_files = :af, updated_at = NOW() WHERE id = :id")
                ->execute(['af' => json_encode($additionalFiles), 'id' => $createdInvoiceIds[0]]);
        } else {
            // No invoices — create skipped records
            foreach ($otherDocs as $item) {
                $skipId = generateId();
                $db->prepare("INSERT INTO invoices (id, company_id, email_inbox_id, source, original_filename, stored_filename, file_type, file_size, status, document_type, skip_reason) VALUES (:id, :cid, :eid, 'email', :fn, :sf, :ft, :fs, 'skipped', :dt, :sr)")
                    ->execute([
                        'id' => $skipId, 'cid' => $email['company_id'], 'eid' => $emailId,
                        'fn' => $item['attachment']['name'], 'sf' => $item['saved']['storedFilename'],
                        'ft' => $item['saved']['fileType'], 'fs' => $item['attachment']['size'] ?? strlen($item['buffer']),
                        'dt' => $item['classification']['category'], 'sr' => $item['classification']['detail'],
                    ]);
                $results[] = ['invoiceId' => $skipId, 'fileName' => $item['attachment']['name'], 'category' => $item['classification']['category'], 'status' => 'skipped'];
            }
        }
    }

    $db->prepare("UPDATE email_inbox SET attachment_count = :c, status = 'processed' WHERE id = :id")
        ->execute(['c' => count($classified), 'id' => $emailId]);

    sendJSON(['action' => 'reprocess-email', 'classified' => count($classified), 'invoices' => count($invoiceDocs), 'other' => count($otherDocs), 'results' => $results]);
}

if ($action === 'test-facet') {
    // Test whether a POST+file-upload+PATCH cycle triggers facet generation in Vecticum.
    // Required: invoiceId (an existing completed invoice to use as source)
    // Optional: strategy (basic|patch-after|patch-before|patch-twice)
    $invoiceId = $_GET['invoiceId'] ?? '';
    $strategy = $_GET['strategy'] ?? 'patch-after';
    if (!$invoiceId) sendJSON(['error' => 'Need invoiceId'], 400);

    $stmt = $db->prepare("SELECT * FROM invoices WHERE id = :id");
    $stmt->execute(['id' => $invoiceId]);
    $invoice = $stmt->fetch();
    if (!$invoice) sendJSON(['error' => 'Invoice not found'], 404);

    require_once __DIR__ . '/../lib/file_storage.php';
    $filePath = getFilePath($invoice['stored_filename']);
    if (!file_exists($filePath)) sendJSON(['error' => 'File not found: ' . $invoice['stored_filename']], 404);

    $token = getVecticumToken($company);
    $steps = [];

    // Use a test-prefixed invoice number so we don't collide with real data
    $testInvNo = 'TEST-FACET-' . substr(md5(uniqid('', true)), 0, 8);

    // Build metadata body (same logic as uploadToVecticum but simpler)
    $total = floatval($invoice['total_amount'] ?? 0);
    $tax = floatval($invoice['tax_amount'] ?? 0);
    $subtotal = floatval($invoice['subtotal_amount'] ?? 0);
    $body = [
        'invoiceNo' => $testInvNo,
        'invoiceDate' => $invoice['invoice_date'],
        'paymentDate' => $invoice['due_date'],
        'invoiceAmount' => $subtotal ?: $total,
        'vatAmount' => $tax,
        'totalAmount' => $subtotal ?: $total,
        'totalInclVat' => number_format($total ?: ($subtotal + $tax), 2, '.', ''),
    ];
    if (!empty($invoice['vendor_vat_id'])) $body['counterpartyCode'] = $invoice['vendor_vat_id'];

    $partner = findVecticumPartner($company, $invoice['vendor_vat_id'] ?? '', $invoice['vendor_name'] ?? '', $token);
    if ($partner) $body['counterparty'] = $partner;

    $currency = strtoupper(trim($invoice['currency'] ?? 'EUR'));
    $currencyRef = findVecticumCurrency($company, $currency, $token);
    if ($currencyRef) $body['currency'] = $currencyRef;

    $body = array_filter($body, fn($v) => $v !== null);

    // Helper to GET the full record (to inspect facet fields)
    $getRecord = function ($recordId) use ($company, $token) {
        $ch = curl_init($company['vecticum_api_base_url'] . '/' . $company['vecticum_company_id'] . '/' . $recordId);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json', "Authorization: Bearer $token"],
            CURLOPT_TIMEOUT => 15,
        ]);
        $r = curl_exec($ch);
        $c = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['httpCode' => $c, 'data' => json_decode($r, true)];
    };

    // Helper to PATCH the record with same body (re-save trick)
    $patchRecord = function ($recordId, $patchBody) use ($company, $token) {
        $ch = curl_init($company['vecticum_api_base_url'] . '/' . $company['vecticum_company_id'] . '/' . $recordId);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'PATCH',
            CURLOPT_POSTFIELDS => json_encode($patchBody),
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                "Authorization: Bearer $token",
            ],
            CURLOPT_TIMEOUT => 30,
        ]);
        $r = curl_exec($ch);
        $c = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['httpCode' => $c, 'response' => $r];
    };

    // Helper to extract facet-related fields from a record
    $facetSummary = function ($recordData) {
        if (!is_array($recordData)) return ['error' => 'not an object'];
        $relevant = [];
        // Dump actual values for fields likely to hold the facet/preview
        foreach (['_facet', 'facet', 'facets', 'thumbnail', 'preview', 'cardImage', 'image', 'images', 'files'] as $k) {
            if (array_key_exists($k, $recordData)) {
                $relevant[$k] = $recordData[$k];
            }
        }
        $relevant['_allKeys'] = array_keys($recordData);
        return $relevant;
    };

    // Helper to try arbitrary endpoints and record the result
    $tryRequest = function ($method, $url, $body = null) use ($token) {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                "Authorization: Bearer $token",
            ],
            CURLOPT_TIMEOUT => 15,
        ];
        if ($body !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($body);
        curl_setopt_array($ch, $opts);
        $r = curl_exec($ch);
        $c = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['method' => $method, 'url' => $url, 'httpCode' => $c, 'response' => substr($r ?? '', 0, 300)];
    };

    try {
        // STEP 1: POST metadata to create the record
        $ch = curl_init($company['vecticum_api_base_url'] . '/' . $company['vecticum_company_id']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                "Authorization: Bearer $token",
            ],
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $createData = json_decode($response, true);
        $steps[] = ['step' => '1-POST-create', 'httpCode' => $httpCode, 'data' => $createData];

        if ($httpCode < 200 || $httpCode >= 300 || empty($createData['id'])) {
            sendJSON(['action' => 'test-facet', 'testInvNo' => $testInvNo, 'error' => 'Create failed', 'steps' => $steps]);
        }
        $recordId = $createData['id'];

        // STEP 2: GET record immediately after create (baseline — no file, no facet)
        $r2 = $getRecord($recordId);
        $steps[] = ['step' => '2-GET-after-create', 'httpCode' => $r2['httpCode'], 'facet' => $facetSummary($r2['data'])];

        // STEP 3: Upload the file
        $fileResult = uploadFileToVecticum($company, $recordId, $filePath, $invoice['original_filename'] ?? 'invoice.pdf', $token);
        $steps[] = ['step' => '3-POST-file', 'result' => $fileResult];

        // STEP 4: GET record after file upload
        $r4 = $getRecord($recordId);
        $steps[] = ['step' => '4-GET-after-file', 'httpCode' => $r4['httpCode'], 'facet' => $facetSummary($r4['data'])];

        // STEP 5: PATCH (re-save) with same body to try triggering facet generation
        if (in_array($strategy, ['patch-after', 'patch-twice'])) {
            $p5 = $patchRecord($recordId, $body);
            $steps[] = ['step' => '5-PATCH-resave', 'httpCode' => $p5['httpCode']];

            // STEP 6: GET record after PATCH
            $r6 = $getRecord($recordId);
            $steps[] = ['step' => '6-GET-after-patch', 'httpCode' => $r6['httpCode'], 'facet' => $facetSummary($r6['data'])];
        }

        // STEP 7: second PATCH for the patch-twice strategy
        if ($strategy === 'patch-twice') {
            $p7 = $patchRecord($recordId, $body);
            $steps[] = ['step' => '7-PATCH-resave-2', 'httpCode' => $p7['httpCode']];
            $r8 = $getRecord($recordId);
            $steps[] = ['step' => '8-GET-after-patch-2', 'httpCode' => $r8['httpCode'], 'facet' => $facetSummary($r8['data'])];
        }

        // PROBE PHASE — only run when strategy=probe
        if ($strategy === 'probe') {
            $base = $company['vecticum_api_base_url'];
            $cid = $company['vecticum_company_id'];
            $probes = [];

            // Try PATCH with _facet field set to various "trigger" values
            $probes[] = $tryRequest('PATCH', "$base/$cid/$recordId", array_merge($body, ['_facet' => null]));
            $probes[] = $tryRequest('PATCH', "$base/$cid/$recordId", array_merge($body, ['_facet' => ' ']));

            // Try various sub-endpoints on the record
            $probes[] = $tryRequest('POST', "$base/$cid/$recordId/save", null);
            $probes[] = $tryRequest('POST', "$base/$cid/$recordId/finalize", null);
            $probes[] = $tryRequest('POST', "$base/$cid/$recordId/refresh", null);
            $probes[] = $tryRequest('POST', "$base/$cid/$recordId/regenerate", null);
            $probes[] = $tryRequest('POST', "$base/$cid/$recordId/regenerateFacet", null);
            $probes[] = $tryRequest('POST', "$base/$cid/$recordId/recompute", null);
            $probes[] = $tryRequest('POST', "$base/$cid/$recordId/commit", null);
            $probes[] = $tryRequest('POST', "$base/$cid/$recordId/publish", null);
            $probes[] = $tryRequest('POST', "$base/$cid/$recordId/_facet", null);
            $probes[] = $tryRequest('POST', "$base/$cid/$recordId/facet", null);

            // Try HEAD/GET on the same URLs (some APIs trigger on GET)
            $probes[] = $tryRequest('GET', "$base/$cid/$recordId/facet", null);
            $probes[] = $tryRequest('GET', "$base/$cid/$recordId/regenerate", null);

            // Try PATCH with explicit flags that might trigger processing
            $probes[] = $tryRequest('PATCH', "$base/$cid/$recordId", array_merge($body, ['regenerateFacet' => true]));
            $probes[] = $tryRequest('PATCH', "$base/$cid/$recordId", array_merge($body, ['triggerFacet' => true]));
            $probes[] = $tryRequest('PATCH', "$base/$cid/$recordId", array_merge($body, ['_regenerate' => true]));
            $probes[] = $tryRequest('PATCH', "$base/$cid/$recordId", array_merge($body, ['_save' => true]));

            // PUT variants (memory says PUT wipes metadata — risky but worth checking status)
            // (skipping actual PUT to avoid wiping the test record)

            // After all probes, GET the record one more time
            $rFinal = $getRecord($recordId);
            $steps[] = ['step' => 'PROBE-final-GET', 'httpCode' => $rFinal['httpCode'], 'facet' => $facetSummary($rFinal['data'])];

            // Return only successful (2xx) probes and the final state
            $hits = array_values(array_filter($probes, fn($p) => $p['httpCode'] >= 200 && $p['httpCode'] < 300));
            $misses = array_values(array_filter($probes, fn($p) => $p['httpCode'] >= 400));

            sendJSON([
                'action' => 'test-facet',
                'strategy' => 'probe',
                'testInvNo' => $testInvNo,
                'recordId' => $recordId,
                'initialSteps' => $steps,
                'probeHits' => $hits,
                'probeMisses' => $misses,
                'finalFacet' => $facetSummary($rFinal['data'] ?? []),
            ]);
        }

        sendJSON([
            'action' => 'test-facet',
            'strategy' => $strategy,
            'testInvNo' => $testInvNo,
            'recordId' => $recordId,
            'steps' => $steps,
            'hint' => 'Check _allKeys in each step for facet-related field names. Compare the GET results before/after PATCH to see if any facet field got populated.',
        ]);
    } catch (\Throwable $e) {
        sendJSON(['action' => 'test-facet', 'error' => $e->getMessage(), 'steps' => $steps], 500);
    }
}

if ($action === 'debug-email-attachments') {
    $emailId = $_GET['emailId'] ?? '';
    if (!$emailId) sendJSON(['error' => 'Need emailId'], 400);
    $stmt = $db->prepare("SELECT * FROM email_inbox WHERE id = :id");
    $stmt->execute(['id' => $emailId]);
    $email = $stmt->fetch();
    if (!$email) sendJSON(['error' => 'Email not found'], 404);

    $cStmt = $db->prepare("SELECT * FROM companies WHERE id = :id");
    $cStmt->execute(['id' => $email['company_id']]);
    $emailCompany = $cStmt->fetch();

    require_once __DIR__ . '/../lib/microsoft_graph.php';
    $attachments = fetchAttachments($emailCompany, $email['message_id']);

    $summary = [];
    foreach ($attachments as $a) {
        $summary[] = [
            'name' => $a['name'] ?? null,
            'contentType' => $a['contentType'] ?? null,
            'size' => $a['size'] ?? null,
            'isInline' => $a['isInline'] ?? null,
            'hasContentBytes' => !empty($a['contentBytes']),
            'contentBytesLength' => strlen($a['contentBytes'] ?? ''),
            '@odata.type' => $a['@odata.type'] ?? null,
        ];
    }
    sendJSON(['action' => 'debug-email-attachments', 'email' => $email['subject'], 'attachmentCount' => count($attachments), 'attachments' => $summary]);
}

if ($action === 'raw-graph-query') {
    // Query Microsoft Graph directly without any filters — to find missed emails
    require_once __DIR__ . '/../lib/microsoft_graph.php';
    $days = max(1, min(30, intval($_GET['days'] ?? 3)));
    $fromFilter = trim($_GET['from'] ?? '');

    $token = getM365Token($company);
    $folder = $company['ms_fetch_folder'] ?: 'INBOX';
    $email = $company['ms_sender_email'];
    $since = gmdate('Y-m-d\TH:i:s\Z', time() - $days * 86400);

    $q = ["\$top" => '100', "\$orderby" => 'receivedDateTime desc', "\$select" => 'id,subject,from,receivedDateTime,hasAttachments,isRead'];
    $filter = "receivedDateTime ge $since";
    if ($fromFilter) $filter .= " and contains(from/emailAddress/address, '" . addslashes($fromFilter) . "')";
    $q["\$filter"] = $filter;
    $url = "https://graph.microsoft.com/v1.0/users/$email/mailFolders/$folder/messages?" . http_build_query($q);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer $token"],
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true);
    $messages = $data['value'] ?? [];

    // Cross-reference with our DB
    $db = getDBConnection();
    $summary = ['total_in_graph' => count($messages), 'in_db' => 0, 'missing_from_db' => 0, 'messages' => []];
    foreach ($messages as $m) {
        // Case-sensitive exact match (guard against utf8mb4_unicode_ci collation collision)
        $stmt = $db->prepare("SELECT id, status, subject FROM email_inbox WHERE message_id = :m COLLATE utf8mb4_bin LIMIT 1");
        $stmt->execute(['m' => $m['id']]);
        $row = $stmt->fetch();
        $inDb = (bool)$row;
        // Also check case-insensitive match to detect collisions
        $stmt2 = $db->prepare("SELECT id, subject FROM email_inbox WHERE message_id = :m LIMIT 1");
        $stmt2->execute(['m' => $m['id']]);
        $row2 = $stmt2->fetch();
        $ciMatchDifferent = $row2 && (!$row || $row['id'] !== $row2['id']);
        if ($inDb) $summary['in_db']++; else $summary['missing_from_db']++;
        if ($ciMatchDifferent) {
            $summary['case_collisions'] = ($summary['case_collisions'] ?? 0) + 1;
        }
        $summary['messages'][] = [
            'subject' => $m['subject'] ?? '',
            'from' => $m['from']['emailAddress']['address'] ?? '',
            'received' => $m['receivedDateTime'] ?? '',
            'isRead' => $m['isRead'] ?? null,
            'hasAttachments' => $m['hasAttachments'] ?? null,
            'in_db' => $inDb,
            'db_status' => $row['status'] ?? null,
            'db_row_id' => $row['id'] ?? null,
            'ci_match_different_row' => $ciMatchDifferent,
            'graph_id_prefix' => substr($m['id'] ?? '', 0, 20),
        ];
        // Also check which company the DB row belongs to (to catch cross-company collisions)
        if ($inDb) {
            $ccStmt = $db->prepare("SELECT c.name AS cname, e.from_email, e.subject FROM email_inbox e LEFT JOIN companies c ON c.id = e.company_id WHERE e.id = :id");
            $ccStmt->execute(['id' => $row['id']]);
            $ccRow = $ccStmt->fetch();
            $idx = count($summary['messages']) - 1;
            $summary['messages'][$idx]['db_company'] = $ccRow['cname'] ?? null;
            $summary['messages'][$idx]['db_from_email'] = $ccRow['from_email'] ?? null;
            $summary['messages'][$idx]['db_subject'] = $ccRow['subject'] ?? null;
        }
    }
    sendJSON([
        'action' => 'raw-graph-query',
        'httpCode' => $code,
        'since' => $since,
        'fromFilter' => $fromFilter,
        'summary' => $summary,
    ]);
}

if ($action === 'fetch') {
    // Fetch any Vecticum endpoint - for exploration
    $endpoint = $_GET['endpoint'] ?? '';
    $full = !empty($_GET['full']);
    if (!$endpoint) sendJSON(['error' => 'Need endpoint param'], 400);
    $token = getVecticumToken($company);
    $url = $company['vecticum_api_base_url'] . '/' . $endpoint;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json', "Authorization: Bearer $token"],
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($response, true);
    $count = is_array($data) ? count($data) : 0;
    // When fetching a single record (object), return the whole thing; for lists, sample first 3
    $isAssoc = is_array($data) && array_keys($data) !== range(0, count($data) - 1);
    $sample = $isAssoc || $full ? $data : (is_array($data) ? array_slice($data, 0, 3) : $data);
    sendJSON(['action' => 'fetch', 'url' => $url, 'httpCode' => $code, 'count' => $count, 'sample' => $sample]);
}

if ($action === 'match-author') {
    $email = $_GET['email'] ?? '';
    if (!$email) sendJSON(['error' => 'Need email param'], 400);
    $token = getVecticumToken($company);
    $result = findVecticumAuthor($company, $email, $token);
    sendJSON(['action' => 'match-author', 'email' => $email, 'match' => $result]);
}

if ($action === 'resolve-author') {
    $email = $_GET['email'] ?? '';
    if (!$email) sendJSON(['error' => 'Need email param'], 400);
    $token = getVecticumToken($company);
    $matched = findVecticumAuthor($company, $email, $token);
    $fallback = getVecticumDefaultAuthor($company, $token);
    sendJSON([
        'action' => 'resolve-author',
        'company' => [
            'id' => $company['id'],
            'name' => $company['name'],
            'ms_sender_email' => $company['ms_sender_email'] ?? null,
            'vecticum_company_id' => $company['vecticum_company_id'] ?? null,
            'vecticum_inbox_setup_id' => $company['vecticum_inbox_setup_id'] ?? null,
        ],
        'email' => $email,
        'matchedAuthor' => $matched,
        'fallbackAuthor' => $fallback,
        'chosenAuthor' => $matched ?: $fallback,
    ]);
}

if ($action === 'match-partner') {
    $vatId = $_GET['vatId'] ?? '';
    $name = $_GET['name'] ?? '';
    if (!$vatId && !$name) sendJSON(['error' => 'Need vatId or name param'], 400);
    $token = getVecticumToken($company);
    $result = findVecticumPartner($company, $vatId, $name, $token);
    sendJSON(['action' => 'match-partner', 'vatId' => $vatId, 'name' => $name, 'match' => $result]);
}

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

if ($action === 'bind-record') {
    $invoiceId = $_GET['invoiceId'] ?? '';
    $recordId = $_GET['recordId'] ?? '';
    if (!$invoiceId || !$recordId) sendJSON(['error' => 'invoiceId and recordId required'], 400);

    $stmt = $db->prepare("SELECT id, company_id, vecticum_id FROM invoices WHERE id = :id AND company_id = :cid");
    $stmt->execute(['id' => $invoiceId, 'cid' => $companyId]);
    $invoice = $stmt->fetch();
    if (!$invoice) sendJSON(['error' => 'Invoice not found'], 404);

    $db->prepare("UPDATE invoices SET vecticum_id = :recordId, vecticum_sent_at = NOW(), vecticum_error = NULL, updated_at = NOW() WHERE id = :id")
        ->execute(['recordId' => $recordId, 'id' => $invoiceId]);

    sendJSON([
        'action' => 'bind-record',
        'success' => true,
        'invoiceId' => $invoiceId,
        'recordId' => $recordId,
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

    if (isset($_GET['debug'])) {
        sendJSON(['debug' => 'pre-upload', 'url' => $url, 'filePath' => $filePath, 'fileExists' => file_exists($filePath), 'fileSize' => filesize($filePath), 'mimeType' => $mimeType, 'fileName' => $fileName, 'recordId' => $recordId]);
    }

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
