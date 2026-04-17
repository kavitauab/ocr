<?php

function getM365Token($company, $forceRefresh = false) {
    // Check cached token
    if (!$forceRefresh && !empty($company['ms_access_token']) && !empty($company['ms_token_expires'])) {
        $expires = strtotime($company['ms_token_expires']);
        if ($expires > time() + 300) {
            return $company['ms_access_token'];
        }
    }

    if (empty($company['ms_tenant_id']) || empty($company['ms_client_id']) || empty($company['ms_client_secret'])) {
        throw new Exception('M365 credentials not configured');
    }

    $ch = curl_init("https://login.microsoftonline.com/{$company['ms_tenant_id']}/oauth2/v2.0/token");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'client_credentials',
            'client_id' => $company['ms_client_id'],
            'client_secret' => $company['ms_client_secret'],
            'scope' => 'https://graph.microsoft.com/.default',
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);
    if (isset($data['error'])) {
        throw new Exception('M365 auth failed: ' . ($data['error_description'] ?? $data['error']));
    }

    $expiresAt = date('Y-m-d H:i:s', time() + $data['expires_in']);
    $db = getDBConnection();
    $stmt = $db->prepare("UPDATE companies SET ms_access_token = :token, ms_token_expires = :expires WHERE id = :id");
    $stmt->execute(['token' => $data['access_token'], 'expires' => $expiresAt, 'id' => $company['id']]);

    return $data['access_token'];
}

function fetchEmails($company, $sinceHours = 72) {
    $token = getM365Token($company);
    $folder = $company['ms_fetch_folder'] ?: 'INBOX';
    $email = $company['ms_sender_email'];
    $since = gmdate('Y-m-d\TH:i:s\Z', time() - $sinceHours * 3600);

    $query = http_build_query([
        '$filter' => "isRead eq false and receivedDateTime ge $since",
        '$orderby' => 'receivedDateTime desc',
        '$top' => '50',
        '$select' => 'id,subject,from,receivedDateTime,hasAttachments,isRead,body',
    ]);
    $url = "https://graph.microsoft.com/v1.0/users/$email/mailFolders/$folder/messages?$query";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer $token"],
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        throw new Exception("Failed to fetch emails: HTTP $httpCode $response");
    }

    $data = json_decode($response, true);
    return $data['value'] ?? [];
}

function normalizeEmailBodyForVecticum($content, $contentType = 'html') {
    $text = (string)$content;
    if ($text === '') {
        return '';
    }

    if (strtolower((string)$contentType) === 'html') {
        $text = preg_replace_callback(
            '/<a\b[^>]*href=["\']?([^"\'>\s]+)[^>]*>(.*?)<\/a>/is',
            function ($matches) {
                $label = trim(html_entity_decode(strip_tags($matches[2] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $href = trim(html_entity_decode($matches[1] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($label === '') return $href;
                if ($href === '' || strcasecmp($label, $href) === 0) return $label;
                return $label . '<' . $href . '>';
            },
            $text
        );
        $text = preg_replace('/<(br|\\/p|\\/div|\\/li|\\/tr|\\/table|\\/h[1-6])\\b[^>]*>/i', "\n", $text);
        $text = preg_replace('/<(p|div|li|tr|table|h[1-6])\\b[^>]*>/i', "\n", $text);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace("/\t+/", ' ', $text);
    $text = preg_replace("/[ \xC2\xA0]+/u", ' ', $text);
    $text = preg_replace("/\n{3,}/", "\n\n", $text);
    return trim($text);
}

function fetchMessageBodyText($company, $messageId) {
    $token = getM365Token($company);
    $email = $company['ms_sender_email'];
    $messageId = trim((string)$messageId);
    if ($messageId === '') return '';

    $query = http_build_query([
        '$select' => 'body',
    ]);
    $ch = curl_init("https://graph.microsoft.com/v1.0/users/{$email}/messages/{$messageId}?{$query}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer $token"],
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) return '';

    $data = json_decode($response, true);
    $body = $data['body'] ?? null;
    if (!is_array($body)) return '';

    return normalizeEmailBodyForVecticum($body['content'] ?? '', $body['contentType'] ?? 'html');
}

function fetchAttachments($company, $messageId) {
    $token = getM365Token($company);
    $email = $company['ms_sender_email'];

    $ch = curl_init("https://graph.microsoft.com/v1.0/users/{$email}/messages/{$messageId}/attachments");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer $token"],
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) throw new Exception("Failed to fetch attachments: HTTP $httpCode");

    $data = json_decode($response, true);
    return $data['value'] ?? [];
}

function markAsRead($company, $messageId) {
    $token = getM365Token($company);
    $email = $company['ms_sender_email'];

    $ch = curl_init("https://graph.microsoft.com/v1.0/users/{$email}/messages/{$messageId}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'PATCH',
        CURLOPT_POSTFIELDS => json_encode(['isRead' => true]),
        CURLOPT_HTTPHEADER => ["Authorization: Bearer $token", 'Content-Type: application/json'],
        CURLOPT_TIMEOUT => 15,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

function sendMail($company, $toEmail, $subject, $body, $contentType = 'Text', $forceRefresh = false) {
    $token = getM365Token($company, $forceRefresh);
    $fromEmail = trim((string)($company['ms_sender_email'] ?? ''));
    $toEmail = trim((string)$toEmail);

    if ($fromEmail === '') {
        throw new Exception('Company sender email is not configured');
    }
    if ($toEmail === '') {
        throw new Exception('Recipient email is required');
    }

    $payload = [
        'message' => [
            'subject' => $subject,
            'body' => [
                'contentType' => $contentType,
                'content' => $body,
            ],
            'toRecipients' => [[
                'emailAddress' => ['address' => $toEmail],
            ]],
        ],
        'saveToSentItems' => true,
    ];

    $ch = curl_init("https://graph.microsoft.com/v1.0/users/{$fromEmail}/sendMail");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $token",
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 202) {
        $data = json_decode($response, true);
        throw new Exception('Failed to send email: ' . ($data['error']['message'] ?? "HTTP $httpCode"));
    }

    return ['success' => true];
}

function replyToMessage($company, $messageId, $body, $contentType = 'Text', $forceRefresh = false) {
    $token = getM365Token($company, $forceRefresh);
    $fromEmail = trim((string)($company['ms_sender_email'] ?? ''));
    $messageId = trim((string)$messageId);

    if ($fromEmail === '') {
        throw new Exception('Company sender email is not configured');
    }
    if ($messageId === '') {
        throw new Exception('Message ID is required to send a threaded reply');
    }

    if (strcasecmp((string)$contentType, 'HTML') === 0) {
        $createCh = curl_init("https://graph.microsoft.com/v1.0/users/{$fromEmail}/messages/{$messageId}/createReply");
        curl_setopt_array($createCh, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => '{}',
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer $token",
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 30,
        ]);
        $createResponse = curl_exec($createCh);
        $createHttpCode = curl_getinfo($createCh, CURLINFO_HTTP_CODE);
        curl_close($createCh);

        if (!in_array($createHttpCode, [200, 201], true)) {
            $data = json_decode($createResponse, true);
            throw new Exception('Failed to create threaded reply draft: ' . ($data['error']['message'] ?? "HTTP $createHttpCode"));
        }

        $draft = json_decode($createResponse, true);
        $draftId = trim((string)($draft['id'] ?? ''));
        if ($draftId === '') {
            throw new Exception('Failed to create threaded reply draft: missing draft id');
        }

        $existingBody = trim((string)($draft['body']['content'] ?? ''));
        $combinedBody = trim((string)$body);
        if ($existingBody !== '') {
            $combinedBody .= ($combinedBody !== '' ? '<br><br>' : '') . $existingBody;
        }

        $patchCh = curl_init("https://graph.microsoft.com/v1.0/users/{$fromEmail}/messages/{$draftId}");
        curl_setopt_array($patchCh, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'PATCH',
            CURLOPT_POSTFIELDS => json_encode([
                'body' => [
                    'contentType' => 'HTML',
                    'content' => $combinedBody,
                ],
            ]),
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer $token",
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 30,
        ]);
        $patchResponse = curl_exec($patchCh);
        $patchHttpCode = curl_getinfo($patchCh, CURLINFO_HTTP_CODE);
        curl_close($patchCh);

        if ($patchHttpCode !== 200) {
            $data = json_decode($patchResponse, true);
            throw new Exception('Failed to format threaded reply draft: ' . ($data['error']['message'] ?? "HTTP $patchHttpCode"));
        }

        $sendCh = curl_init("https://graph.microsoft.com/v1.0/users/{$fromEmail}/messages/{$draftId}/send");
        curl_setopt_array($sendCh, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => '{}',
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer $token",
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 30,
        ]);
        $sendResponse = curl_exec($sendCh);
        $sendHttpCode = curl_getinfo($sendCh, CURLINFO_HTTP_CODE);
        curl_close($sendCh);

        if ($sendHttpCode !== 202) {
            $data = json_decode($sendResponse, true);
            throw new Exception('Failed to send threaded reply draft: ' . ($data['error']['message'] ?? "HTTP $sendHttpCode"));
        }
    } else {
        $payload = ['comment' => $body];

        $ch = curl_init("https://graph.microsoft.com/v1.0/users/{$fromEmail}/messages/{$messageId}/reply");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer $token",
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 202) {
            $data = json_decode($response, true);
            throw new Exception('Failed to send threaded reply: ' . ($data['error']['message'] ?? "HTTP $httpCode"));
        }
    }

    return ['success' => true];
}

function testM365Connection($company) {
    try {
        $token = getM365Token($company);
        $email = $company['ms_sender_email'];

        $ch = curl_init("https://graph.microsoft.com/v1.0/users/{$email}/mailFolders/INBOX?\$select=displayName,totalItemCount");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer $token"],
            CURLOPT_TIMEOUT => 15,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            return ['success' => false, 'error' => "Graph API error: HTTP $httpCode $response"];
        }

        $data = json_decode($response, true);
        return ['success' => true, 'email' => "Connected to {$email} ({$data['totalItemCount']} messages in Inbox)"];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}
