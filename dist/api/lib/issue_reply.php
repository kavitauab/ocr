<?php

require_once __DIR__ . '/microsoft_graph.php';

function normalizeIssueReplyTemplate($template) {
    $template = str_replace(["\r\n", "\r"], "\n", (string)$template);
    $template = trim($template);

    if ($template !== '' && strpos($template, "\n") === false) {
        $template = preg_replace('/,\s*We could not complete processing/i', ",\n\nWe could not complete processing", $template);
        $template = preg_replace('/\.\s*\{issue\}/', ".\n\nIssue:\n{issue}", $template);
        $template = preg_replace('/\{issue\}\s*Please review the document/i', "{issue}\n\nPlease review the document", $template);
        $template = preg_replace('/\.\s*Please review the document/i', ".\n\nPlease review the document", $template);
        $template = preg_replace('/\.\s*Regards,\s*/i', ".\n\nRegards,\n", $template);
    }

    $template = preg_replace("/\n{3,}/", "\n\n", $template);
    return trim($template);
}

function renderIssueReplyHtml($text) {
    $text = trim(str_replace(["\r\n", "\r"], "\n", (string)$text));
    if ($text === '') return '';

    $paragraphs = preg_split("/\n\s*\n/", $text) ?: [];
    $htmlParts = [];

    foreach ($paragraphs as $paragraph) {
        $paragraph = trim($paragraph);
        if ($paragraph === '') continue;

        $lines = array_values(array_filter(array_map('trim', explode("\n", $paragraph)), fn($line) => $line !== ''));
        if (!$lines) continue;

        $isBulletList = count($lines) > 1 && count(array_filter($lines, fn($line) => preg_match('/^[-*]\s+/', $line))) === count($lines);
        if ($isBulletList) {
            $items = array_map(fn($line) => '<li>' . htmlspecialchars(trim(preg_replace('/^[-*]\s+/', '', $line)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>', $lines);
            $htmlParts[] = '<ul style="margin:0 0 16px 20px;padding:0;">' . implode('', $items) . '</ul>';
            continue;
        }

        $escapedLines = array_map(fn($line) => htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $lines);
        $htmlParts[] = '<p style="margin:0 0 16px 0;">' . implode('<br>', $escapedLines) . '</p>';
    }

    return '<div style="font-family:Segoe UI,Arial,sans-serif;font-size:14px;line-height:1.55;color:#111827;">'
        . implode('', $htmlParts)
        . '</div>';
}

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
    $defaultBody = normalizeIssueReplyTemplate((string)getSetting(
        'issue_reply_body',
        "Hello {senderName},\n\nWe could not complete processing for \"{reference}\".\n\nIssue:\n{issue}\n\nPlease review the document and resend a corrected version if needed.\n\nRegards,\n{companyName}"
    ));

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

    $bodyText = strtr($defaultBody, $replacements);

    return [
        'toEmail' => trim((string)($invoice['sender_email'] ?? '')),
        'messageId' => trim((string)($invoice['email_message_id'] ?? '')),
        'subject' => strtr($defaultSubject, $replacements),
        'body' => $bodyText,
        'bodyHtml' => renderIssueReplyHtml($bodyText),
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
            replyToMessage($company, $draft['messageId'], $draft['bodyHtml'] ?: $draft['body'], $draft['bodyHtml'] ? 'HTML' : 'Text');
        } else {
            sendMail($company, $draft['toEmail'], $draft['subject'], $draft['bodyHtml'] ?: $draft['body'], $draft['bodyHtml'] ? 'HTML' : 'Text');
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
