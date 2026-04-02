<?php

require_once __DIR__ . '/microsoft_graph.php';
require_once __DIR__ . '/file_storage.php';
require_once __DIR__ . '/claude.php';
require_once __DIR__ . '/usage.php';

$ALLOWED_ATTACHMENT_TYPES = ['application/pdf', 'image/png', 'image/jpeg', 'image/jpg'];

function processCompanyEmails($companyId) {
    global $ALLOWED_ATTACHMENT_TYPES;
    $db = getDBConnection();

    $stmt = $db->prepare("SELECT * FROM companies WHERE id = :id");
    $stmt->execute(['id' => $companyId]);
    $company = $stmt->fetch();

    if (!$company || !$company['ms_fetch_enabled']) {
        return ['fetched' => 0, 'processed' => 0, 'errors' => ['Email fetch not enabled']];
    }

    $errors = [];
    $fetched = 0;
    $processed = 0;

    try {
        $messages = fetchEmails($company);
    } catch (Exception $e) {
        return ['fetched' => 0, 'processed' => 0, 'errors' => [$e->getMessage()]];
    }
    $fetched = count($messages);

    foreach ($messages as $message) {
        try {
            // Check for duplicate
            $stmt = $db->prepare("SELECT id FROM email_inbox WHERE message_id = :messageId LIMIT 1");
            $stmt->execute(['messageId' => $message['id']]);
            if ($stmt->fetch()) continue;

            $emailId = generateId();
            $fromEmail = $message['from']['emailAddress']['address'] ?? null;
            $fromName = $message['from']['emailAddress']['name'] ?? null;

            $stmt = $db->prepare("INSERT INTO email_inbox (id, company_id, message_id, subject, from_email, from_name, received_date, has_attachments, status) VALUES (:id, :companyId, :messageId, :subject, :fromEmail, :fromName, :receivedDate, :hasAttachments, 'processing')");
            $stmt->execute([
                'id' => $emailId,
                'companyId' => $companyId,
                'messageId' => $message['id'],
                'subject' => $message['subject'] ?? '',
                'fromEmail' => $fromEmail,
                'fromName' => $fromName,
                'receivedDate' => $message['receivedDateTime'] ?? null,
                'hasAttachments' => $message['hasAttachments'] ? 1 : 0,
            ]);

            if (!$message['hasAttachments']) {
                $db->prepare("UPDATE email_inbox SET status = 'processed', attachment_count = 0 WHERE id = :id")->execute(['id' => $emailId]);
                continue;
            }

            $attachments = fetchAttachments($company, $message['id']);
            $invoiceAttachments = array_filter($attachments, function($a) use ($ALLOWED_ATTACHMENT_TYPES) {
                return in_array(strtolower($a['contentType'] ?? ''), $ALLOWED_ATTACHMENT_TYPES)
                    && empty($a['isInline'])
                    && !empty($a['contentBytes']);
            });

            $db->prepare("UPDATE email_inbox SET attachment_count = :count WHERE id = :id")->execute(['count' => count($invoiceAttachments), 'id' => $emailId]);

            foreach ($invoiceAttachments as $attachment) {
                try {
                    $buffer = base64_decode($attachment['contentBytes']);
                    $saved = saveFile($buffer, $attachment['name'], $companyId);
                    $invoiceId = generateId();

                    $stmt = $db->prepare("INSERT INTO invoices (id, company_id, email_inbox_id, source, original_filename, stored_filename, file_type, file_size, status) VALUES (:id, :companyId, :emailId, 'email', :originalFilename, :storedFilename, :fileType, :fileSize, 'processing')");
                    $stmt->execute([
                        'id' => $invoiceId,
                        'companyId' => $companyId,
                        'emailId' => $emailId,
                        'originalFilename' => $attachment['name'],
                        'storedFilename' => $saved['storedFilename'],
                        'fileType' => $saved['fileType'],
                        'fileSize' => $attachment['size'] ?? strlen($buffer),
                    ]);

                    try {
                        $filePath = getFilePath($saved['storedFilename']);
                        $ocrRequestStarted = false;
                        $ocrUsage = ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-20250514'];
                        $ocrJobId = startOcrJob($invoiceId, $companyId, 'anthropic', 'claude-sonnet-4-20250514');
                        try {
                            $db->prepare("UPDATE invoices SET ocr_sent_at = NOW(), updated_at = NOW() WHERE id = :id")
                                ->execute(['id' => $invoiceId]);
                        } catch (Throwable $e) {
                            // Allow processing to continue on DBs not yet migrated with ocr_sent_at.
                        }

                        $ocrRequestStarted = true;
                        $extractionResult = extractInvoiceData($filePath, $saved['fileType'], null, true);
                        $extracted = $extractionResult['data'] ?? $extractionResult;
                        if (isset($extractionResult['usage']) && is_array($extractionResult['usage'])) {
                            $ocrUsage = $extractionResult['usage'];
                        }

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
                        try {
                            $stmt = $db->prepare("UPDATE invoices SET status = 'completed',
                                document_type = :documentType,
                                invoice_number = :invoiceNumber, invoice_date = :invoiceDate, due_date = :dueDate,
                                vendor_name = :vendorName, vendor_address = :vendorAddress, vendor_vat_id = :vendorVatId,
                                buyer_name = :buyerName, buyer_address = :buyerAddress, buyer_vat_id = :buyerVatId,
                                total_amount = :totalAmount, currency = :currency, tax_amount = :taxAmount,
                                subtotal_amount = :subtotalAmount, po_number = :poNumber, payment_terms = :paymentTerms,
                                bank_details = :bankDetails, confidence_scores = :confidence, raw_extraction = :raw,
                                ocr_returned_at = NOW(), updated_at = NOW() WHERE id = :id");
                            $stmt->execute($invoiceUpdateParams);
                        } catch (Throwable $e) {
                            // Fallback for DBs without ocr_returned_at.
                            $stmt = $db->prepare("UPDATE invoices SET status = 'completed',
                                document_type = :documentType,
                                invoice_number = :invoiceNumber, invoice_date = :invoiceDate, due_date = :dueDate,
                                vendor_name = :vendorName, vendor_address = :vendorAddress, vendor_vat_id = :vendorVatId,
                                buyer_name = :buyerName, buyer_address = :buyerAddress, buyer_vat_id = :buyerVatId,
                                total_amount = :totalAmount, currency = :currency, tax_amount = :taxAmount,
                                subtotal_amount = :subtotalAmount, po_number = :poNumber, payment_terms = :paymentTerms,
                                bank_details = :bankDetails, confidence_scores = :confidence, raw_extraction = :raw,
                                updated_at = NOW() WHERE id = :id");
                            $stmt->execute($invoiceUpdateParams);
                        }
                        completeOcrJob($ocrJobId, $ocrUsage);
                        trackInvoiceProcessed($companyId, $attachment['size'] ?? strlen($buffer), $ocrUsage);
                        $processed++;

                        // Auto-send to Vecticum if enabled
                        if ($company['vecticum_enabled'] && $company['vecticum_auto_send']) {
                            try {
                                require_once __DIR__ . '/vecticum.php';
                                $invStmt = $db->prepare("SELECT * FROM invoices WHERE id = :id");
                                $invStmt->execute(['id' => $invoiceId]);
                                $updatedInv = $invStmt->fetch();

                                $buyerOk = true;
                                $bkw = trim($company['buyer_keywords'] ?? '');
                                if ($bkw && !empty($updatedInv['buyer_name'])) {
                                    $buyerOk = false;
                                    $bn = strtolower($updatedInv['buyer_name']);
                                    foreach (explode(',', $bkw) as $kw) {
                                        if (trim($kw) && strpos($bn, strtolower(trim($kw))) !== false) { $buyerOk = true; break; }
                                    }
                                }

                                if ($buyerOk && empty($updatedInv['vecticum_id'])) {
                                    $fp = getFilePath($saved['storedFilename']);
                                    $vecResult = uploadToVecticum($company, [
                                        'invoiceNumber' => $updatedInv['invoice_number'],
                                        'invoiceDate' => $updatedInv['invoice_date'],
                                        'dueDate' => $updatedInv['due_date'],
                                        'vendorName' => $updatedInv['vendor_name'],
                                        'vendorVatId' => $updatedInv['vendor_vat_id'],
                                        'subtotalAmount' => $updatedInv['subtotal_amount'],
                                        'taxAmount' => $updatedInv['tax_amount'],
                                        'totalAmount' => $updatedInv['total_amount'],
                                        'currency' => $updatedInv['currency'],
                                        '_filePath' => $fp,
                                        '_fileName' => $updatedInv['original_filename'],
                                        '_senderEmail' => $fromEmail,
                                    ]);
                                    if ($vecResult['success'] && !empty($vecResult['externalId'])) {
                                        $db->prepare("UPDATE invoices SET vecticum_id = :vid, vecticum_sent_at = NOW(), updated_at = NOW() WHERE id = :id")
                                            ->execute(['vid' => $vecResult['externalId'], 'id' => $invoiceId]);
                                    }
                                }
                            } catch (\Throwable $vecErr) {
                                error_log("Email auto-send to Vecticum failed for $invoiceId: " . $vecErr->getMessage());
                            }
                        }
                    } catch (Exception $e) {
                        try {
                            $db->prepare("UPDATE invoices
                                SET status = 'failed',
                                    processing_error = :error,
                                    ocr_returned_at = CASE WHEN ocr_sent_at IS NULL THEN ocr_returned_at ELSE NOW() END,
                                    updated_at = NOW()
                                WHERE id = :id")
                                ->execute(['error' => $e->getMessage(), 'id' => $invoiceId]);
                        } catch (Throwable $e2) {
                            $db->prepare("UPDATE invoices SET status = 'failed', processing_error = :error, updated_at = NOW() WHERE id = :id")
                                ->execute(['error' => $e->getMessage(), 'id' => $invoiceId]);
                        }
                        if (!empty($ocrRequestStarted)) {
                            if (isset($ocrJobId)) {
                                failOcrJob($ocrJobId, $e->getMessage(), $ocrUsage ?? null);
                            }
                            trackApiCall($companyId, $ocrUsage ?? ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-20250514']);
                        }
                    }
                } catch (Exception $e) {
                    $errors[] = "Attachment {$attachment['name']}: " . $e->getMessage();
                }
            }

            $db->prepare("UPDATE email_inbox SET status = 'processed' WHERE id = :id")->execute(['id' => $emailId]);

            try { markAsRead($company, $message['id']); } catch (Exception $e) { /* Non-critical */ }
        } catch (Exception $e) {
            $errors[] = "Message {$message['subject']}: " . $e->getMessage();
        }
    }

    return ['fetched' => $fetched, 'processed' => $processed, 'errors' => $errors];
}
