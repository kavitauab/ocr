<?php

require_once __DIR__ . '/microsoft_graph.php';
require_once __DIR__ . '/file_storage.php';
require_once __DIR__ . '/claude.php';
require_once __DIR__ . '/usage.php';
require_once __DIR__ . '/issue_reply.php';

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
                $contentType = strtolower($a['contentType'] ?? '');
                $fileName = strtolower($a['name'] ?? '');
                $ext = pathinfo($fileName, PATHINFO_EXTENSION);
                $typeOk = in_array($contentType, $ALLOWED_ATTACHMENT_TYPES)
                    || ($contentType === 'application/octet-stream' && in_array($ext, ['pdf', 'png', 'jpg', 'jpeg']));
                return $typeOk && empty($a['isInline']) && !empty($a['contentBytes']);
            });

            $db->prepare("UPDATE email_inbox SET attachment_count = :count WHERE id = :id")->execute(['count' => count($invoiceAttachments), 'id' => $emailId]);

            if (empty($invoiceAttachments)) {
                $db->prepare("UPDATE email_inbox SET status = 'processed' WHERE id = :id")->execute(['id' => $emailId]);
                continue;
            }

            // ========================================
            // TWO-PASS APPROACH: Classify then Extract
            // ========================================

            // PASS 1: Save all files and classify each document
            $classified = [];
            foreach ($invoiceAttachments as $attachment) {
                try {
                    $buffer = base64_decode($attachment['contentBytes']);
                    $saved = saveFile($buffer, $attachment['name'], $companyId);
                    $filePath = getFilePath($saved['storedFilename']);

                    // Classify with Haiku (cheap)
                    $classification = classifyDocument($filePath, $saved['fileType']);

                    // Track classification API usage
                    if (!empty($classification['usage'])) {
                        trackApiCall($companyId, $classification['usage']);
                    }

                    $classified[] = [
                        'attachment' => $attachment,
                        'saved' => $saved,
                        'buffer' => $buffer,
                        'classification' => $classification,
                        'filePath' => $filePath,
                    ];
                } catch (Exception $e) {
                    $errors[] = "Classification of {$attachment['name']}: " . $e->getMessage();
                }
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

            // Process invoice documents (full OCR extraction)
            $createdInvoiceIds = [];
            foreach ($invoiceDocs as $item) {
                try {
                    $attachment = $item['attachment'];
                    $saved = $item['saved'];
                    $invoiceId = generateId();

                    $db->prepare("INSERT INTO invoices (id, company_id, email_inbox_id, source, original_filename, stored_filename, file_type, file_size, status) VALUES (:id, :companyId, :emailId, 'email', :originalFilename, :storedFilename, :fileType, :fileSize, 'processing')")
                        ->execute([
                            'id' => $invoiceId, 'companyId' => $companyId, 'emailId' => $emailId,
                            'originalFilename' => $attachment['name'],
                            'storedFilename' => $saved['storedFilename'],
                            'fileType' => $saved['fileType'],
                            'fileSize' => $attachment['size'] ?? strlen($item['buffer']),
                        ]);

                    $ocrUsage = ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-20250514'];
                    $ocrJobId = startOcrJob($invoiceId, $companyId, 'anthropic', 'claude-sonnet-4-20250514');
                    try {
                        $db->prepare("UPDATE invoices SET ocr_sent_at = NOW(), updated_at = NOW() WHERE id = :id")->execute(['id' => $invoiceId]);
                    } catch (Throwable $e) {}

                    // Load company extraction field preferences
                    $enabledFields = null;
                    if ($company['extraction_fields']) {
                        $ef = is_string($company['extraction_fields']) ? json_decode($company['extraction_fields'], true) : $company['extraction_fields'];
                        if (is_array($ef) && !empty($ef)) $enabledFields = $ef;
                    }

                    $extractionResult = extractInvoiceData($item['filePath'], $saved['fileType'], $enabledFields, true);
                    $extracted = $extractionResult['data'] ?? $extractionResult;
                    if (isset($extractionResult['usage'])) $ocrUsage = $extractionResult['usage'];
                    $modelUsed = $extractionResult['model_used'] ?? ($ocrUsage['model'] ?? 'unknown');
                    $escalated = $extractionResult['escalated'] ?? false;
                    $escalationReason = $extractionResult['escalation_reason'] ?? null;

                    // Strip fields not in enabledFields (enforce company settings)
                    if ($enabledFields !== null) {
                        $allFieldKeys = array_keys(getAllExtractionFields());
                        foreach ($allFieldKeys as $fk) {
                            if (!in_array($fk, $enabledFields) && isset($extracted[$fk])) {
                                unset($extracted[$fk]);
                                if (isset($extracted['confidence'][$fk])) unset($extracted['confidence'][$fk]);
                            }
                        }
                    }

                    // Map order_confirmation to proforma
                    $docType = normalizeDocumentType($extracted['documentType'] ?? $item['classification']['category'], $extracted);
                    if ($docType === 'order_confirmation') $docType = 'proforma';

                    $invoiceUpdateParams = [
                        'documentType' => $docType,
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
                        'ocrModel' => $modelUsed ?: 'unknown',
                        'ocrEscalated' => $escalated ? 1 : 0,
                        'ocrEscalationReason' => $escalationReason,
                        'id' => $invoiceId,
                    ];

                    $db->prepare("UPDATE invoices SET status = 'completed',
                        document_type = :documentType,
                        invoice_number = :invoiceNumber, invoice_date = :invoiceDate, due_date = :dueDate,
                        vendor_name = :vendorName, vendor_address = :vendorAddress, vendor_vat_id = :vendorVatId,
                        buyer_name = :buyerName, buyer_address = :buyerAddress, buyer_vat_id = :buyerVatId,
                        total_amount = :totalAmount, currency = :currency, tax_amount = :taxAmount,
                        subtotal_amount = :subtotalAmount, po_number = :poNumber, payment_terms = :paymentTerms,
                        bank_details = :bankDetails, confidence_scores = :confidence, raw_extraction = :raw,
                        ocr_model = :ocrModel, ocr_escalated = :ocrEscalated, ocr_escalation_reason = :ocrEscalationReason,
                        ocr_returned_at = NOW(), updated_at = NOW() WHERE id = :id")
                        ->execute($invoiceUpdateParams);

                    completeOcrJob($ocrJobId, $ocrUsage);
                    trackInvoiceProcessed($companyId, $attachment['size'] ?? strlen($item['buffer']), $ocrUsage);
                    $createdInvoiceIds[] = $invoiceId;
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
                                    'documentType' => $updatedInv['document_type'],
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
                                    $db->prepare("UPDATE invoices SET vecticum_id = :vid, vecticum_sent_at = NOW(), vecticum_error = NULL, updated_at = NOW() WHERE id = :id")
                                        ->execute(['vid' => $vecResult['externalId'], 'id' => $invoiceId]);

                                    // Upload additional files to Vecticum
                                    if (!empty($otherDocs)) {
                                        foreach ($otherDocs as $otherItem) {
                                            try {
                                                uploadAdditionalFileToVecticum($company, $vecResult['externalId'], $otherItem['filePath'], $otherItem['attachment']['name']);
                                            } catch (\Throwable $e) {
                                                error_log("Vecticum additional file upload failed: " . $e->getMessage());
                                            }
                                        }
                                    }
                                } else {
                                    $db->prepare("UPDATE invoices SET vecticum_error = :err, updated_at = NOW() WHERE id = :id")
                                        ->execute(['err' => $vecResult['error'] ?? 'Unknown error', 'id' => $invoiceId]);
                                    sendIssueReplyForInvoice($db, $invoiceId, 'vecticum_failed');
                                }
                            } elseif (!$buyerOk) {
                                sendIssueReplyForInvoice($db, $invoiceId, 'buyer_mismatch');
                            }
                        } catch (\Throwable $vecErr) {
                            error_log("Email auto-send to Vecticum failed for $invoiceId: " . $vecErr->getMessage());
                            $db->prepare("UPDATE invoices SET vecticum_error = :err, updated_at = NOW() WHERE id = :id")
                                ->execute(['err' => $vecErr->getMessage(), 'id' => $invoiceId]);
                            sendIssueReplyForInvoice($db, $invoiceId, 'vecticum_failed');
                        }
                    }

                } catch (Exception $e) {
                    // OCR failed for this attachment
                    if (isset($invoiceId)) {
                        $db->prepare("UPDATE invoices SET status = 'failed', processing_error = :error, updated_at = NOW() WHERE id = :id")
                            ->execute(['error' => $e->getMessage(), 'id' => $invoiceId]);
                        if (isset($ocrJobId)) failOcrJob($ocrJobId, $e->getMessage(), $ocrUsage ?? null);
                    }
                    $errors[] = "Extraction of {$item['attachment']['name']}: " . $e->getMessage();
                }
            }

            // Handle other documents (non-invoices)
            if (!empty($otherDocs)) {
                if (!empty($createdInvoiceIds)) {
                    // Link as additional files to the first invoice
                    $primaryInvoiceId = $createdInvoiceIds[0];
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
                    }
                    $db->prepare("UPDATE invoices SET additional_files = :af, updated_at = NOW() WHERE id = :id")
                        ->execute(['af' => json_encode($additionalFiles), 'id' => $primaryInvoiceId]);
                } else {
                    // No invoices in this email — create skipped records
                    foreach ($otherDocs as $item) {
                        $skipId = generateId();
                        $db->prepare("INSERT INTO invoices (id, company_id, email_inbox_id, source, original_filename, stored_filename, file_type, file_size, status, document_type, skip_reason) VALUES (:id, :companyId, :emailId, 'email', :fn, :sf, :ft, :fs, 'skipped', :dt, :sr)")
                            ->execute([
                                'id' => $skipId, 'companyId' => $companyId, 'emailId' => $emailId,
                                'fn' => $item['attachment']['name'],
                                'sf' => $item['saved']['storedFilename'],
                                'ft' => $item['saved']['fileType'],
                                'fs' => $item['attachment']['size'] ?? strlen($item['buffer']),
                                'dt' => $item['classification']['category'],
                                'sr' => $item['classification']['detail'],
                            ]);
                    }
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
