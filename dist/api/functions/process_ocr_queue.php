<?php
// Cron endpoint - process queued OCR jobs with retry support.
// Auth: CRON_SECRET bearer token
// Call: GET /api/cron/process-ocr

$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if (CRON_SECRET && !preg_match('/Bearer\s+' . preg_quote(CRON_SECRET, '/') . '/', $authHeader)) {
    sendJSON(['error' => 'Unauthorized'], 401);
}

set_time_limit(300); // Allow up to 5 minutes for batch processing

require_once __DIR__ . '/../lib/file_storage.php';
require_once __DIR__ . '/../lib/claude.php';
require_once __DIR__ . '/../lib/usage.php';
require_once __DIR__ . '/../lib/rate_limit.php';

$db = getDBConnection();

$batchSize = min(5, max(1, intval($_GET['batch'] ?? 3)));

$summary = [
    'processed' => 0,
    'succeeded' => 0,
    'failed' => 0,
    'retrying' => 0,
    'skipped_rate_limit' => 0,
    'jobs' => [],
];

// Pick up queued and retrying jobs that are ready to process
try {
    $stmt = $db->prepare("
        SELECT j.*, i.stored_filename, i.file_type, i.company_id as invoice_company_id, i.file_size
        FROM ocr_jobs j
        INNER JOIN invoices i ON i.id = j.invoice_id
        WHERE j.status IN ('queued', 'retrying')
          AND (j.next_retry_at IS NULL OR j.next_retry_at <= NOW())
        ORDER BY j.queued_at ASC
        LIMIT :batchSize
    ");
    $stmt->bindValue(':batchSize', $batchSize, PDO::PARAM_INT);
    $stmt->execute();
    $jobs = $stmt->fetchAll();
} catch (\Throwable $e) {
    sendJSON(['error' => 'Failed to query queue: ' . $e->getMessage()], 500);
    return;
}

if (empty($jobs)) {
    sendJSON(['message' => 'No jobs in queue', 'processed' => 0]);
    return;
}

foreach ($jobs as $job) {
    $jobId = $job['id'];
    $invoiceId = $job['invoice_id'];
    $companyId = $job['company_id'];
    $attempt = (int)$job['attempt'];
    $maxAttempts = (int)$job['max_attempts'];

    $jobResult = [
        'jobId' => $jobId,
        'invoiceId' => $invoiceId,
        'companyId' => $companyId,
        'attempt' => $attempt,
        'status' => 'unknown',
    ];

    // Check rate limit before processing
    $rateCheck = checkRateLimit($companyId);
    if (!$rateCheck['allowed']) {
        $jobResult['status'] = 'skipped_rate_limit';
        $jobResult['reason'] = $rateCheck['reason'];
        $summary['skipped_rate_limit']++;
        $summary['jobs'][] = $jobResult;
        continue;
    }

    // Mark job as processing
    try {
        $db->prepare("UPDATE ocr_jobs SET status = 'processing', updated_at = NOW() WHERE id = :id")
            ->execute(['id' => $jobId]);
        $db->prepare("UPDATE invoices SET status = 'processing', ocr_sent_at = NOW(), updated_at = NOW() WHERE id = :id")
            ->execute(['id' => $invoiceId]);
    } catch (\Throwable $e) {
        error_log("Queue: Failed to mark job $jobId as processing: " . $e->getMessage());
        continue;
    }

    $ocrUsage = ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-20250514'];

    try {
        // Load company extraction field preferences
        $companyStmt = $db->prepare("SELECT extraction_fields FROM companies WHERE id = :id");
        $companyStmt->execute(['id' => $companyId]);
        $companyRow = $companyStmt->fetch();
        $enabledFields = null;
        if ($companyRow && $companyRow['extraction_fields']) {
            $enabledFields = json_decode($companyRow['extraction_fields'], true);
        }

        $filePath = getFilePath($job['stored_filename']);
        if (!file_exists($filePath)) {
            throw new \RuntimeException("File not found: " . $job['stored_filename']);
        }

        // Call Claude for extraction
        $extractionResult = extractInvoiceData($filePath, $job['file_type'], $enabledFields, true);
        $extracted = $extractionResult['data'] ?? $extractionResult;
        if (isset($extractionResult['usage']) && is_array($extractionResult['usage'])) {
            $ocrUsage = $extractionResult['usage'];
        }

        // Update invoice with extracted data
        $invoiceUpdateParams = [
            'documentType' => $extracted['documentType'] ?? null,
            'invoiceNumber' => $extracted['invoiceNumber'] ?? null,
            'invoiceDate' => $extracted['invoiceDate'] ?? null,
            'dueDate' => $extracted['dueDate'] ?? null,
            'vendorName' => $extracted['vendorName'] ?? null,
            'vendorAddress' => $extracted['vendorAddress'] ?? null,
            'vendorVatId' => $extracted['vendorVatId'] ?? null,
            'buyerName' => $extracted['buyerName'] ?? null,
            'buyerAddress' => $extracted['buyerAddress'] ?? null,
            'buyerVatId' => $extracted['buyerVatId'] ?? null,
            'totalAmount' => isset($extracted['totalAmount']) ? (string)$extracted['totalAmount'] : null,
            'currency' => $extracted['currency'] ?? null,
            'taxAmount' => isset($extracted['taxAmount']) ? (string)$extracted['taxAmount'] : null,
            'subtotalAmount' => isset($extracted['subtotalAmount']) ? (string)$extracted['subtotalAmount'] : null,
            'poNumber' => $extracted['poNumber'] ?? null,
            'paymentTerms' => $extracted['paymentTerms'] ?? null,
            'bankDetails' => $extracted['bankDetails'] ?? null,
            'confidence' => json_encode($extracted['confidence'] ?? []),
            'raw' => json_encode($extracted),
            'id' => $invoiceId,
        ];

        $stmt = $db->prepare("UPDATE invoices SET status = 'completed',
            document_type = :documentType,
            invoice_number = :invoiceNumber, invoice_date = :invoiceDate, due_date = :dueDate,
            vendor_name = :vendorName, vendor_address = :vendorAddress, vendor_vat_id = :vendorVatId,
            buyer_name = :buyerName, buyer_address = :buyerAddress, buyer_vat_id = :buyerVatId,
            total_amount = :totalAmount, currency = :currency, tax_amount = :taxAmount,
            subtotal_amount = :subtotalAmount, po_number = :poNumber, payment_terms = :paymentTerms,
            bank_details = :bankDetails, confidence_scores = :confidence, raw_extraction = :raw,
            processing_error = NULL, ocr_returned_at = NOW(), updated_at = NOW() WHERE id = :id");
        $stmt->execute($invoiceUpdateParams);

        // Complete the OCR job
        $db->prepare("UPDATE ocr_jobs SET
            status = 'completed',
            request_id = :requestId,
            input_tokens = :inputTokens,
            output_tokens = :outputTokens,
            total_tokens = :totalTokens,
            cache_creation_input_tokens = :cacheCreation,
            cache_read_input_tokens = :cacheRead,
            cost_usd = :costUsd,
            returned_at = NOW(),
            updated_at = NOW()
            WHERE id = :id")
            ->execute([
                'requestId' => $ocrUsage['requestId'] ?? null,
                'inputTokens' => $ocrUsage['inputTokens'] ?? $ocrUsage['input_tokens'] ?? 0,
                'outputTokens' => $ocrUsage['outputTokens'] ?? $ocrUsage['output_tokens'] ?? 0,
                'totalTokens' => $ocrUsage['totalTokens'] ?? $ocrUsage['total_tokens'] ?? 0,
                'cacheCreation' => $ocrUsage['cacheCreationInputTokens'] ?? $ocrUsage['cache_creation_input_tokens'] ?? 0,
                'cacheRead' => $ocrUsage['cacheReadInputTokens'] ?? $ocrUsage['cache_read_input_tokens'] ?? 0,
                'costUsd' => number_format(($ocrUsage['costUsd'] ?? $ocrUsage['cost_usd'] ?? 0), 6, '.', ''),
                'id' => $jobId,
            ]);

        trackInvoiceProcessed($companyId, (int)$job['file_size'], $ocrUsage);

        $jobResult['status'] = 'completed';
        $summary['succeeded']++;

        // Auto-send to Vecticum if enabled
        try {
            $companyStmt = $db->prepare("SELECT * FROM companies WHERE id = :id");
            $companyStmt->execute(['id' => $companyId]);
            $companyData = $companyStmt->fetch();

            if ($companyData && $companyData['vecticum_enabled'] && $companyData['vecticum_auto_send']) {
                require_once __DIR__ . '/../lib/vecticum.php';

                // Re-fetch invoice with updated data
                $invStmt = $db->prepare("SELECT * FROM invoices WHERE id = :id");
                $invStmt->execute(['id' => $invoiceId]);
                $updatedInvoice = $invStmt->fetch();

                // Check buyer mismatch
                $buyerOk = true;
                $buyerKeywords = trim($companyData['buyer_keywords'] ?? '');
                if ($buyerKeywords && !empty($updatedInvoice['buyer_name'])) {
                    $buyerOk = false;
                    $buyerNameLower = strtolower($updatedInvoice['buyer_name']);
                    foreach (explode(',', $buyerKeywords) as $kw) {
                        if (trim($kw) && strpos($buyerNameLower, strtolower(trim($kw))) !== false) {
                            $buyerOk = true;
                            break;
                        }
                    }
                }

                if ($buyerOk && empty($updatedInvoice['vecticum_id'])) {
                    $uploadDir = rtrim(UPLOAD_DIR, '/');
                    $filePath = $uploadDir . '/' . $updatedInvoice['stored_filename'];
                    $senderEmail = null;
                    if (!empty($updatedInvoice['email_inbox_id'])) {
                        $emailStmt = $db->prepare("SELECT from_email FROM email_inbox WHERE id = :id");
                        $emailStmt->execute(['id' => $updatedInvoice['email_inbox_id']]);
                        $emailRow = $emailStmt->fetch();
                        if ($emailRow) $senderEmail = $emailRow['from_email'];
                    }

                    $vecResult = uploadToVecticum($companyData, [
                        'documentType' => $updatedInvoice['document_type'],
                        'invoiceNumber' => $updatedInvoice['invoice_number'],
                        'invoiceDate' => $updatedInvoice['invoice_date'],
                        'dueDate' => $updatedInvoice['due_date'],
                        'vendorName' => $updatedInvoice['vendor_name'],
                        'vendorVatId' => $updatedInvoice['vendor_vat_id'],
                        'subtotalAmount' => $updatedInvoice['subtotal_amount'],
                        'taxAmount' => $updatedInvoice['tax_amount'],
                        'totalAmount' => $updatedInvoice['total_amount'],
                        'currency' => $updatedInvoice['currency'],
                        '_filePath' => $filePath,
                        '_fileName' => $updatedInvoice['original_filename'],
                        '_senderEmail' => $senderEmail,
                    ]);

                    if ($vecResult['success'] && !empty($vecResult['externalId'])) {
                        $db->prepare("UPDATE invoices SET vecticum_id = :vid, vecticum_sent_at = NOW(), updated_at = NOW() WHERE id = :id")
                            ->execute(['vid' => $vecResult['externalId'], 'id' => $invoiceId]);
                        $jobResult['vecticumAutoSend'] = 'success';
                    } else {
                        $jobResult['vecticumAutoSend'] = 'failed: ' . ($vecResult['error'] ?? 'unknown');
                    }
                } elseif (!$buyerOk) {
                    $jobResult['vecticumAutoSend'] = 'skipped: buyer mismatch';
                }
            }
        } catch (\Throwable $vecErr) {
            error_log("Queue: Auto-send to Vecticum failed for $invoiceId: " . $vecErr->getMessage());
            $jobResult['vecticumAutoSend'] = 'error: ' . $vecErr->getMessage();
        }

    } catch (\Throwable $e) {
        error_log("Queue: Job $jobId failed (attempt $attempt): " . $e->getMessage());

        $nextAttempt = $attempt + 1;

        if ($nextAttempt <= $maxAttempts) {
            // Schedule retry with exponential backoff: 30s, 2min, 8min
            $delaySeconds = 30 * pow(4, $attempt - 1);
            $db->prepare("UPDATE ocr_jobs SET
                status = 'retrying',
                attempt = :nextAttempt,
                next_retry_at = DATE_ADD(NOW(), INTERVAL :delay SECOND),
                error_message = :error,
                updated_at = NOW()
                WHERE id = :id")
                ->execute([
                    'nextAttempt' => $nextAttempt,
                    'delay' => $delaySeconds,
                    'error' => $e->getMessage(),
                    'id' => $jobId,
                ]);

            $db->prepare("UPDATE invoices SET status = 'retrying', processing_error = :error, updated_at = NOW() WHERE id = :id")
                ->execute(['error' => "Attempt $attempt failed, retrying in {$delaySeconds}s: " . $e->getMessage(), 'id' => $invoiceId]);

            $jobResult['status'] = 'retrying';
            $jobResult['nextRetryIn'] = $delaySeconds;
            $summary['retrying']++;
        } else {
            // Permanent failure
            $db->prepare("UPDATE ocr_jobs SET
                status = 'failed',
                error_message = :error,
                returned_at = NOW(),
                updated_at = NOW()
                WHERE id = :id")
                ->execute(['error' => $e->getMessage(), 'id' => $jobId]);

            $db->prepare("UPDATE invoices SET status = 'failed', processing_error = :error, ocr_returned_at = NOW(), updated_at = NOW() WHERE id = :id")
                ->execute(['error' => $e->getMessage(), 'id' => $invoiceId]);

            // Track usage even on failure
            try {
                trackApiCall($companyId, $ocrUsage);
            } catch (\Throwable $e2) {
                // non-critical
            }

            $jobResult['status'] = 'failed';
            $jobResult['error'] = $e->getMessage();
            $summary['failed']++;
        }
    }

    $summary['processed']++;
    $summary['jobs'][] = $jobResult;
}

sendJSON($summary);
