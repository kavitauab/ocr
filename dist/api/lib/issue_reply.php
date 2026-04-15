<?php

require_once __DIR__ . '/microsoft_graph.php';

function getInvoiceIssueReplyContext($db, $invoiceId) {
    $stmt = $db->prepare("SELECT
        i.*,
        c.name as company_name,
        c.ms_sender_email,
        e.message_id as email_message_id,
        e.from_email as sender_email,
        e.from_name as sender_name,
        e.subject as email_subject
        FROM invoices i
        LEFT JOIN companies c ON c.id = i.company_id
        LEFT JOIN email_inbox e ON e.id = i.email_inbox_id
        WHERE i.id = :id");
    $stmt->execute(['id' => $invoiceId]);
    return $stmt->fetch();
}

function buildIssueReplyDraft($invoice, $customMessage = null, $reason = '') {
    $issueText = trim((string)$customMessage);
    if ($issueText === '') {
        if ($reason === 'buyer_mismatch') {
            $buyerName = trim((string)($invoice['buyer_name'] ?? ''));
            $companyName = trim((string)($invoice['company_name'] ?? ''));
            $buyerVat = trim((string)($invoice['buyer_vat_id'] ?? ''));
            $issueText = "The document appears to be addressed to a different company"
                . ($buyerName !== '' ? " ({$buyerName}" . ($buyerVat !== '' ? ", {$buyerVat}" : '') . ")" : '')
                . ($companyName !== '' ? " instead of {$companyName}" : '')
                . ". Please resend it to the correct company mailbox or send the corrected invoice.";
        } else {
            $vecticumError = trim((string)($invoice['vecticum_error'] ?? ''));
            $processingError = trim((string)($invoice['processing_error'] ?? ''));

            if ($vecticumError !== '') {
                if (preg_match('/already exists|already exist|duplicate/i', $vecticumError)) {
                    $issueText = 'The upload to Vecticum failed because this document appears to already exist in our accounting system.';
                } else {
                    $issueText = 'The upload to Vecticum failed: ' . $vecticumError;
                }
            } elseif ($processingError !== '') {
                $issueText = 'The document could not be processed automatically: ' . $processingError;
            } else {
                $issueText = 'We could not complete automatic processing for this document and need a corrected version or clarification.';
            }
        }
    }

    $reference = trim((string)($invoice['invoice_number'] ?? '')) ?: trim((string)($invoice['original_filename'] ?? '')) ?: trim((string)($invoice['id'] ?? ''));
    $subjectBase = trim((string)($invoice['email_subject'] ?? '')) ?: ('Invoice ' . $reference);
    $defaultSubject = trim((string)getSetting('issue_reply_subject', 'Re: {emailSubject}'));
    $defaultBody = (string)getSetting(
        'issue_reply_body',
        "Hello {senderName},\n\nWe could not complete processing for \"{reference}\".\n\n{issue}\n\nPlease review the document and resend a corrected version if needed.\n\nRegards,\n{companyName}"
    );

    $replacements = [
        '{senderName}' => trim((string)($invoice['sender_name'] ?? '')) ?: 'there',
        '{senderEmail}' => trim((string)($invoice['sender_email'] ?? '')),
        '{reference}' => $reference,
        '{invoiceNumber}' => trim((string)($invoice['invoice_number'] ?? '')),
        '{fileName}' => trim((string)($invoice['original_filename'] ?? '')),
        '{emailSubject}' => $subjectBase,
        '{companyName}' => trim((string)($invoice['company_name'] ?? '')) ?: 'Accounting',
        '{issue}' => $issueText,
        '{reason}' => $reason,
        '{vecticumError}' => trim((string)($invoice['vecticum_error'] ?? '')),
        '{processingError}' => trim((string)($invoice['processing_error'] ?? '')),
    ];

    return [
        'toEmail' => trim((string)($invoice['sender_email'] ?? '')),
        'messageId' => trim((string)($invoice['email_message_id'] ?? '')),
        'subject' => strtr($defaultSubject, $replacements),
        'body' => strtr($defaultBody, $replacements),
    ];
}

function shouldAutoReplyForReason($reason) {
    if ($reason === 'buyer_mismatch') {
        return getSetting('auto_issue_reply_on_buyer_mismatch', '1') === '1';
    }
    if ($reason === 'vecticum_failed') {
        return getSetting('auto_issue_reply_on_vecticum_failure', '1') === '1';
    }
    return false;
}

function sendIssueReplyForInvoice($db, $invoiceId, $reason, $customMessage = null, $force = false) {
    $invoice = getInvoiceIssueReplyContext($db, $invoiceId);
    if (!$invoice) {
        return ['success' => false, 'status' => 'missing', 'error' => 'Invoice not found'];
    }
    if (($invoice['source'] ?? '') !== 'email') {
        return ['success' => false, 'status' => 'skipped', 'error' => 'Invoice did not originate from email'];
    }
    if (trim((string)($invoice['sender_email'] ?? '')) === '') {
        return ['success' => false, 'status' => 'skipped', 'error' => 'Invoice has no sender email'];
    }
    if (!$force && !shouldAutoReplyForReason($reason)) {
        return ['success' => false, 'status' => 'skipped', 'error' => 'Auto reply disabled for this reason'];
    }
    if (!$force && !empty($invoice['issue_reply_sent_at']) && ($invoice['issue_reply_reason'] ?? '') === $reason) {
        return ['success' => false, 'status' => 'skipped', 'error' => 'Issue reply already sent for this reason'];
    }

    $companyStmt = $db->prepare("SELECT * FROM companies WHERE id = :id");
    $companyStmt->execute(['id' => $invoice['company_id']]);
    $company = $companyStmt->fetch();
    if (!$company) {
        return ['success' => false, 'status' => 'missing', 'error' => 'Company not found'];
    }

    $draft = buildIssueReplyDraft($invoice, $customMessage, $reason);

    try {
        if ($draft['messageId'] !== '') {
            replyToMessage($company, $draft['messageId'], $draft['body']);
        } else {
            sendMail($company, $draft['toEmail'], $draft['subject'], $draft['body']);
        }

        $db->prepare("UPDATE invoices SET issue_reply_sent_at = NOW(), issue_reply_reason = :reason, issue_reply_error = NULL, updated_at = NOW() WHERE id = :id")
            ->execute(['reason' => $reason, 'id' => $invoiceId]);

        return [
            'success' => true,
            'status' => 'sent',
            'recipient' => $draft['toEmail'],
            'subject' => $draft['subject'],
            'reason' => $reason,
        ];
    } catch (\Throwable $e) {
        $db->prepare("UPDATE invoices SET issue_reply_error = :error, updated_at = NOW() WHERE id = :id")
            ->execute(['error' => $e->getMessage(), 'id' => $invoiceId]);

        return ['success' => false, 'status' => 'failed', 'error' => $e->getMessage(), 'reason' => $reason];
    }
}
