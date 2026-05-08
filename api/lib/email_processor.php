<?php

require_once __DIR__ . '/microsoft_graph.php';
require_once __DIR__ . '/file_storage.php';
require_once __DIR__ . '/claude.php';
require_once __DIR__ . '/extraction_config.php';
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
            $emailId = generateId();
            $fromEmail = $message['from']['emailAddress']['address'] ?? null;
            $fromName = $message['from']['emailAddress']['name'] ?? null;
            $emailBodyText = normalizeEmailBodyForVecticum($message['body']['content'] ?? '', $message['body']['contentType'] ?? 'html');

            // INSERT IGNORE relies on UNIQUE index on message_id to prevent
            // duplicate rows when two cron runs race on the same mailbox.
            $stmt = $db->prepare("INSERT IGNORE INTO email_inbox (id, company_id, message_id, subject, from_email, from_name, received_date, has_attachments, status) VALUES (:id, :companyId, :messageId, :subject, :fromEmail, :fromName, :receivedDate, :hasAttachments, 'processing')");
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
            if ($stmt->rowCount() === 0) {
                // Already in DB (dedup'd by unique index on message_id).
                // Still try to mark as read in Microsoft — covers the case where
                // the previous ingestion succeeded in DB but failed the PATCH to
                // Graph, leaving the email stuck as unread and refetched forever.
                $existingStmt = $db->prepare("SELECT status FROM email_inbox WHERE message_id = :mid LIMIT 1");
                $existingStmt->execute(['mid' => $message['id']]);
                $existing = $existingStmt->fetch();
                if ($existing && $existing['status'] === 'processed') {
                    try { markAsRead($company, $message['id']); } catch (Exception $e) { /* Non-critical */ }
                }
                continue;
            }

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

            // PASS 1: Save all files and classify each document.
            // When classification throws (timeout / API error / unreadable file),
            // we DO NOT silently drop the attachment — a synthetic classification
            // is recorded so the file still gets a DB row downstream.
            $classified = [];
            foreach ($invoiceAttachments as $attachment) {
                $buffer = null;
                $saved = null;
                $filePath = null;
                $classification = null;
                $classifyError = null;
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
                } catch (Exception $e) {
                    $classifyError = $e->getMessage();
                    $errors[] = "Classification of {$attachment['name']}: " . $classifyError;
                }

                // If we never got a saved file (saveFile itself failed) we have
                // nothing to record — skip entirely. Otherwise always record.
                if ($saved === null) continue;

                if ($classification === null) {
                    // Classification call failed. PDFs in business mail are
                    // overwhelmingly invoices — assume invoice so the user sees
                    // the doc rather than losing it. Other types stay "other".
                    $isPdf = ($saved['fileType'] ?? '') === 'pdf';
                    $classification = [
                        'category' => $isPdf ? 'invoice' : 'other',
                        'detail'   => 'Classification failed: ' . ($classifyError ?? 'unknown error') . ($isPdf ? ' — defaulting PDF to invoice' : ''),
                        'confidence' => 0.0,
                    ];
                }

                $classified[] = [
                    'attachment' => $attachment,
                    'saved' => $saved,
                    'buffer' => $buffer,
                    'classification' => $classification,
                    'filePath' => $filePath,
                ];
            }

            // PASS 1b: heuristic recovery — if classification returned "other"
            // for a PDF whose filename clearly looks like an invoice (INV_*,
            // contains "invoice"/"saskaita"/"faktur"/"rēķin"), override to
            // invoice. Tiny PNGs/JPEGs (<40 KB) inside the same email are
            // almost always logos/signatures that should stay "other".
            foreach ($classified as &$item) {
                $cat = $item['classification']['category'] ?? 'other';
                if ($cat !== 'other') continue;
                $ft = $item['saved']['fileType'] ?? '';
                $name = strtolower($item['attachment']['name'] ?? '');
                if ($ft === 'pdf') {
                    $looksLikeInvoiceName = (
                        strpos($name, 'inv') === 0
                        || strpos($name, 'invoice') !== false
                        || strpos($name, 'saskaita') !== false
                        || strpos($name, 'sąskaita') !== false
                        || strpos($name, 'faktur') !== false
                        || strpos($name, 'rēķin') !== false
                        || strpos($name, 'rekin') !== false
                    );
                    if ($looksLikeInvoiceName) {
                        $item['classification']['category'] = 'invoice';
                        $item['classification']['detail']   = 'Filename-based override (PDF named like an invoice; classifier returned other)';
                    }
                }
            }
            unset($item);

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

            // PASS 2b: rescue — if every passed-filter attachment ended up in
            // otherDocs but at least one of them is a PDF the classifier was
            // UNCERTAIN about (category="other"), the largest such PDF is
            // almost certainly the actual invoice (forwarded inline logos and
            // signature images get picked up alongside the real PDF). Promote
            // it to invoiceDocs so it gets full OCR.
            //
            // IMPORTANT: do NOT promote PDFs the classifier explicitly tagged
            // as "act" / "report" / "contract" / "proforma" / "credit_note" /
            // "order_confirmation" — those decisions are intentional and a
            // rescue would override them with the wrong document type.
            if (empty($invoiceDocs) && !empty($otherDocs)) {
                $bestPdfIdx = -1;
                $bestPdfSize = 0;
                foreach ($otherDocs as $idx => $item) {
                    if (($item['saved']['fileType'] ?? '') !== 'pdf') continue;
                    $cat = $item['classification']['category'] ?? 'other';
                    if ($cat !== 'other') continue; // never override an explicit non-invoice classification
                    $size = (int)($item['attachment']['size'] ?? strlen((string)$item['buffer']));
                    if ($size > $bestPdfSize) {
                        $bestPdfSize = $size;
                        $bestPdfIdx = $idx;
                    }
                }
                if ($bestPdfIdx >= 0) {
                    $promoted = $otherDocs[$bestPdfIdx];
                    $promoted['classification']['category'] = 'invoice';
                    $promoted['classification']['detail']   = 'Rescue: only unclassified PDF among non-invoices — promoted to primary';
                    $invoiceDocs[] = $promoted;
                    array_splice($otherDocs, $bestPdfIdx, 1);
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

                    $defaultModel = getSetting('extraction_model', 'claude-sonnet-4-6');
                    $ocrUsage = ['provider' => 'anthropic', 'model' => $defaultModel];
                    $ocrJobId = startOcrJob($invoiceId, $companyId, 'anthropic', $defaultModel);
                    try {
                        $db->prepare("UPDATE invoices SET ocr_sent_at = NOW(), updated_at = NOW() WHERE id = :id")->execute(['id' => $invoiceId]);
                    } catch (Throwable $e) {}

                    // Load + validate company extraction field preferences
                    $enabledFields = loadCompanyExtractionFields($db, $companyId);

                    $extractionResult = extractInvoiceData($item['filePath'], $saved['fileType'], $enabledFields, true);
                    $extracted = $extractionResult['data'] ?? $extractionResult;
                    if (isset($extractionResult['usage'])) $ocrUsage = $extractionResult['usage'];
                    $modelUsed = $extractionResult['model_used'] ?? ($ocrUsage['model'] ?? 'unknown');
                    $escalated = $extractionResult['escalated'] ?? false;
                    $escalationReason = $extractionResult['escalation_reason'] ?? null;

                    // Strip fields not in enabledFields (enforce company settings)
                    $extracted = stripDisabledExtractionFields($extracted, $enabledFields);

                    // Auto-correct buyer ↔ vendor swap when the model flipped
                    // them (logo on buyer side / unusual party ordering).
                    $swapped = detectAndSwapBuyerVendor($extracted, $company);
                    if ($swapped) {
                        error_log("email_processor: auto-corrected buyer/vendor swap for invoice $invoiceId");
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
                        'vendorCompanyCode' => $extracted['vendorCompanyCode'] ?? null,
                        'buyerName' => $extracted['buyerName'] ?? null,
                        'buyerAddress' => $extracted['buyerAddress'] ?? null,
                        'buyerVatId' => $extracted['buyerVatId'] ?? null,
                        'buyerCompanyCode' => $extracted['buyerCompanyCode'] ?? null,
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

                    // Invoice completion + ocr_job completion are atomic.
                    try {
                        $db->beginTransaction();
                        $db->prepare("UPDATE invoices SET status = 'completed',
                            document_type = :documentType,
                            invoice_number = :invoiceNumber, invoice_date = :invoiceDate, due_date = :dueDate,
                            vendor_name = :vendorName, vendor_address = :vendorAddress, vendor_vat_id = :vendorVatId,
                            vendor_company_code = :vendorCompanyCode,
                            buyer_name = :buyerName, buyer_address = :buyerAddress, buyer_vat_id = :buyerVatId,
                            buyer_company_code = :buyerCompanyCode,
                            total_amount = :totalAmount, currency = :currency, tax_amount = :taxAmount,
                            subtotal_amount = :subtotalAmount, po_number = :poNumber, payment_terms = :paymentTerms,
                            bank_details = :bankDetails, confidence_scores = :confidence, raw_extraction = :raw,
                            ocr_model = :ocrModel, ocr_escalated = :ocrEscalated, ocr_escalation_reason = :ocrEscalationReason,
                            ocr_returned_at = NOW(), updated_at = NOW() WHERE id = :id")
                            ->execute($invoiceUpdateParams);
                        completeOcrJob($ocrJobId, $ocrUsage);
                        $db->commit();
                    } catch (\Throwable $txErr) {
                        if ($db->inTransaction()) $db->rollBack();
                        throw $txErr;
                    }
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

                            // Buyer match — any of name keywords, VAT, or company code
                            // is sufficient. Default to OK only when we have no
                            // identifiers to compare (preserves prior behaviour for
                            // companies that haven't filled in any settings yet).
                            $hasAnyPartyId = !empty($updatedInv['buyer_name'])
                                || !empty($updatedInv['buyer_vat_id'])
                                || !empty($updatedInv['buyer_company_code']);
                            $hasCompanyId = !empty($company['vat_number'])
                                || !empty($company['code'])
                                || !empty($company['buyer_keywords']);
                            if ($hasAnyPartyId && $hasCompanyId) {
                                $buyerOk = partyMatchesCompany(
                                    $updatedInv['buyer_name']         ?? null,
                                    $updatedInv['buyer_vat_id']       ?? null,
                                    $updatedInv['buyer_company_code'] ?? null,
                                    $company
                                );
                            } else {
                                $buyerOk = true;
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
                                    '_emailBody' => $emailBodyText,
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
                                    sendIssueReplyForInvoice($db, $invoiceId, ($vecResult['reason'] ?? '') === 'invalid_document' ? 'invalid_document' : 'vecticum_failed');
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
                    // Inline OCR failed for this attachment. Instead of marking
                    // the invoice as permanently failed, hand the job back to
                    // the queue worker so it gets retried with exponential
                    // backoff (30s, 2min, 8min). Permanent failure is only
                    // reached after max_attempts (default 3) in the queue.
                    if (isset($invoiceId)) {
                        $errMsg = $e->getMessage();
                        $rescheduled = false;
                        if (isset($ocrJobId) && $ocrJobId) {
                            try {
                                // Look up current attempt + max_attempts; if we
                                // can still retry, set status='retrying' with a
                                // 30s delay; the cron worker will pick it up.
                                $jstmt = $db->prepare("SELECT attempt, max_attempts FROM ocr_jobs WHERE id = :id");
                                $jstmt->execute(['id' => $ocrJobId]);
                                $jrow = $jstmt->fetch();
                                $curAttempt = (int)($jrow['attempt'] ?? 1);
                                $maxAttempts = (int)($jrow['max_attempts'] ?? 3);
                                if ($curAttempt < $maxAttempts) {
                                    $delaySeconds = 30 * pow(4, max(0, $curAttempt - 1));
                                    $db->beginTransaction();
                                    $db->prepare("UPDATE ocr_jobs SET
                                        status = 'retrying',
                                        attempt = :nextAttempt,
                                        next_retry_at = DATE_ADD(NOW(), INTERVAL :delay SECOND),
                                        error_message = :error,
                                        updated_at = NOW()
                                        WHERE id = :id")
                                        ->execute([
                                            'nextAttempt' => $curAttempt + 1,
                                            'delay' => $delaySeconds,
                                            'error' => $errMsg,
                                            'id' => $ocrJobId,
                                        ]);
                                    $db->prepare("UPDATE invoices SET status = 'retrying', processing_error = :error, updated_at = NOW() WHERE id = :id")
                                        ->execute([
                                            'error' => "Attempt $curAttempt failed, retrying in {$delaySeconds}s: " . $errMsg,
                                            'id' => $invoiceId,
                                        ]);
                                    $db->commit();
                                    $rescheduled = true;
                                }
                            } catch (\Throwable $rsErr) {
                                if ($db->inTransaction()) $db->rollBack();
                                error_log("email_processor: failed to reschedule OCR job $ocrJobId: " . $rsErr->getMessage());
                            }
                        }
                        if (!$rescheduled) {
                            // Couldn't reschedule (max attempts reached, no
                            // job id, or DB error) — record permanent failure.
                            $db->prepare("UPDATE invoices SET status = 'failed', processing_error = :error, updated_at = NOW() WHERE id = :id")
                                ->execute(['error' => $errMsg, 'id' => $invoiceId]);
                            if (isset($ocrJobId)) failOcrJob($ocrJobId, $errMsg, $ocrUsage ?? null);
                        }
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
                    $firstSkippedId = null;
                    $firstSkippedReason = null;
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
                        if ($firstSkippedId === null) {
                            $firstSkippedId = $skipId;
                            $firstSkippedReason = $item['classification']['detail'] ?: $item['classification']['category'];
                        }
                    }
                    if ($firstSkippedId !== null) {
                        sendIssueReplyForInvoice($db, $firstSkippedId, 'invalid_document', 'The attached document could not be processed because it was classified as a non-invoice document: ' . $firstSkippedReason . '. Please resend the actual invoice document.');
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
