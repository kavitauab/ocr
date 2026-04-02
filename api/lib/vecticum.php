<?php

function getVecticumToken($company) {
    if (!empty($company['vecticum_access_token']) && !empty($company['vecticum_token_expires'])) {
        $expires = strtotime($company['vecticum_token_expires']);
        if ($expires > time() + 300) {
            return $company['vecticum_access_token'];
        }
    }

    if (empty($company['vecticum_api_base_url']) || empty($company['vecticum_client_id']) || empty($company['vecticum_client_secret'])) {
        throw new Exception('Vecticum credentials not configured');
    }

    $ch = curl_init($company['vecticum_api_base_url'] . '/oauth/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: ' . json_encode(['client_id' => $company['vecticum_client_id'], 'client_secret' => $company['vecticum_client_secret']]),
        ],
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);
    if (empty($data['success']) || empty($data['token'])) {
        throw new Exception('Vecticum auth failed: ' . ($data['message'] ?? 'No token returned'));
    }

    $expiresAt = date('Y-m-d H:i:s', time() + 23 * 3600);
    $db = getDBConnection();
    $stmt = $db->prepare("UPDATE companies SET vecticum_access_token = :token, vecticum_token_expires = :expires WHERE id = :id");
    $stmt->execute(['token' => $data['token'], 'expires' => $expiresAt, 'id' => $company['id']]);

    return $data['token'];
}

function testVecticumConnection($company) {
    try {
        $token = getVecticumToken($company);
        if (!empty($company['vecticum_company_id'])) {
            $ch = curl_init($company['vecticum_api_base_url'] . '/' . $company['vecticum_company_id']);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Accept: application/json', "Authorization: Bearer $token"],
                CURLOPT_TIMEOUT => 15,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) return ['success' => false, 'error' => "Endpoint returned $httpCode"];
            $data = json_decode($response, true);
            $count = is_array($data) ? count($data) : 0;
            return ['success' => true, 'message' => "Connected. Found $count records."];
        }
        return ['success' => true, 'message' => 'Authentication successful'];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function _stripDiacritics($str) {
    $map = ['ą'=>'a','č'=>'c','ę'=>'e','ė'=>'e','į'=>'i','š'=>'s','ų'=>'u','ū'=>'u','ž'=>'z',
            'Ą'=>'A','Č'=>'C','Ę'=>'E','Ė'=>'E','Į'=>'I','Š'=>'S','Ų'=>'U','Ū'=>'U','Ž'=>'Z',
            'ä'=>'a','ö'=>'o','ü'=>'u','ß'=>'ss','à'=>'a','á'=>'a','â'=>'a','ã'=>'a','å'=>'a',
            'è'=>'e','é'=>'e','ê'=>'e','ë'=>'e','ì'=>'i','í'=>'i','î'=>'i','ï'=>'i',
            'ò'=>'o','ó'=>'o','ô'=>'o','õ'=>'o','ù'=>'u','ú'=>'u','û'=>'u','ý'=>'y','ñ'=>'n'];
    return strtr($str, $map);
}

function findVecticumPartner($company, $vatId, $companyName, $token = null) {
    if (!$token) $token = getVecticumToken($company);
    if (empty($company['vecticum_partner_endpoint'])) return null;

    $url = $company['vecticum_api_base_url'] . '/' . $company['vecticum_partner_endpoint'];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json', "Authorization: Bearer $token"],
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) return null;
    $partners = json_decode($response, true);
    if (!is_array($partners)) return null;

    // Match by VAT number first (most reliable)
    if ($vatId) {
        $normalizedVat = preg_replace('/\s+/', '', strtoupper($vatId));
        foreach ($partners as $p) {
            $pVat = preg_replace('/\s+/', '', strtoupper($p['vatNumber'] ?? ''));
            if ($pVat && $pVat === $normalizedVat) {
                return ['id' => $p['id'], 'name' => $p['name'] ?? ''];
            }
        }
        // Try matching company code against VAT suffix
        foreach ($partners as $p) {
            $pCode = trim($p['companyCode'] ?? '');
            if ($pCode && strpos($normalizedVat, $pCode) !== false) {
                return ['id' => $p['id'], 'name' => $p['name'] ?? ''];
            }
        }
    }

    // Fallback: match by company name (fuzzy)
    if ($companyName) {
        $normalizedName = _stripDiacritics(strtolower(trim($companyName)));
        // Remove common suffixes for comparison
        $cleanName = preg_replace('/\b(uab|ab|mb|vsi|ii|bv|gmbh|ltd|llc|s\.?a\.?|srl)\b/i', '', $normalizedName);
        $cleanName = trim(preg_replace('/[,."\'"\s]+$/', '', trim($cleanName)));

        foreach ($partners as $p) {
            $pName = _stripDiacritics(strtolower(trim($p['name'] ?? '')));
            $pClean = preg_replace('/\b(uab|ab|mb|vsi|ii|bv|gmbh|ltd|llc|s\.?a\.?|srl)\b/i', '', $pName);
            $pClean = trim(preg_replace('/[,."\'"\s]+$/', '', trim($pClean)));

            if ($pClean && $cleanName && ($pClean === $cleanName || strpos($pClean, $cleanName) !== false || strpos($cleanName, $pClean) !== false)) {
                return ['id' => $p['id'], 'name' => $p['name'] ?? ''];
            }
        }
    }

    return null;
}

function findVecticumAuthor($company, $senderEmail, $token = null) {
    if (!$senderEmail) return null;
    if (!$token) $token = getVecticumToken($company);

    // Fetch persons from Vecticum
    $url = $company['vecticum_api_base_url'] . '/person';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json', "Authorization: Bearer $token"],
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) return null;
    $persons = json_decode($response, true);
    if (!is_array($persons)) return null;

    $normalizedEmail = strtolower(trim($senderEmail));

    foreach ($persons as $p) {
        $pEmail = strtolower(trim($p['email'] ?? ''));
        $pPersonalEmail = strtolower(trim($p['personalEmail'] ?? ''));
        if (($pEmail && $pEmail === $normalizedEmail) || ($pPersonalEmail && $pPersonalEmail === $normalizedEmail)) {
            return ['id' => $p['id'], 'name' => $p['name'] ?? ''];
        }
    }

    return null;
}

function uploadToVecticum($company, $metadata) {
    if (empty($company['vecticum_company_id'])) {
        return ['success' => false, 'error' => 'Vecticum endpoint ID not configured'];
    }

    try {
        $token = getVecticumToken($company);

        $total = floatval($metadata['totalAmount'] ?? 0);
        $tax = floatval($metadata['taxAmount'] ?? 0);
        $subtotal = floatval($metadata['subtotalAmount'] ?? 0);
        $totalInclVat = number_format($total && $tax ? $total + $tax : $total, 2, '.', '');

        $body = [
            'invoiceNo' => $metadata['invoiceNumber'] ?? null,
            'invoiceDate' => $metadata['invoiceDate'] ?? null,
            'paymentDate' => $metadata['dueDate'] ?? null,
            'invoiceAmount' => $subtotal ?: $total,
            'vatAmount' => $tax,
            'totalAmount' => $subtotal ?: $total,
            'totalInclVat' => $totalInclVat,
        ];

        // Set "from" field to sender email
        if (!empty($metadata['_senderEmail'])) {
            $body['from'] = $metadata['_senderEmail'];
        }

        if (!empty($metadata['vendorName'])) {
            $body['description'] = $metadata['vendorName'];
        }
        if (!empty($metadata['vendorVatId'])) {
            $body['counterpartyCode'] = $metadata['vendorVatId'];
        }

        $currency = strtoupper(trim($metadata['currency'] ?? 'EUR'));
        if ($currency === 'EUR') {
            $body['currency'] = ['id' => 'O18j5zeck1yHYb5W4H86', 'name' => 'EUR'];
        } else {
            // Try name-only — Vecticum may resolve the ID
            $body['currency'] = ['name' => $currency];
        }

        // Match author by sender email
        $author = null;
        if (!empty($metadata['_senderEmail'])) {
            $author = findVecticumAuthor($company, $metadata['_senderEmail'], $token);
        }
        if ($author) {
            $body['author'] = $author;
        } elseif (!empty($company['vecticum_author_id'])) {
            $body['author'] = ['id' => $company['vecticum_author_id'], 'name' => $company['vecticum_author_name'] ?? ''];
        }

        // Match partner/counterparty
        $partner = findVecticumPartner($company, $metadata['vendorVatId'] ?? '', $metadata['vendorName'] ?? '', $token);
        if ($partner) {
            $body['counterparty'] = $partner;
        }

        // Remove null values
        $body = array_filter($body, fn($v) => $v !== null);

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

        if ($httpCode < 200 || $httpCode >= 300) {
            $errData = json_decode($response, true);
            $errorMsg = $errData['message'] ?? "Vecticum API error: HTTP $httpCode";
            return ['success' => false, 'error' => $errorMsg];
        }

        $data = json_decode($response, true);
        $externalId = $data['id'] ?? null;

        // Upload file if available
        if ($externalId && !empty($metadata['_filePath']) && file_exists($metadata['_filePath'])) {
            $fileResult = uploadFileToVecticum($company, $externalId, $metadata['_filePath'], $metadata['_fileName'] ?? 'invoice.pdf', $token);
            return ['success' => true, 'externalId' => $externalId, 'fileUpload' => $fileResult];
        }

        return ['success' => true, 'externalId' => $externalId];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function uploadFileToVecticum($company, $documentId, $filePath, $fileName, $token = null) {
    if (!$token) {
        $token = getVecticumToken($company);
    }

    if (!file_exists($filePath)) {
        return ['success' => false, 'error' => 'File not found: ' . $filePath];
    }

    $mimeType = function_exists('mime_content_type') ? (mime_content_type($filePath) ?: 'application/octet-stream') : 'application/pdf';

    // POST multipart to /files/{classId}/{documentId}/files
    // Use filename as the multipart field name — Vecticum stores it as the file name
    $url = $company['vecticum_api_base_url'] . '/files/' . $company['vecticum_company_id'] . '/' . $documentId . '/files';
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
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 201) {
        return ['success' => true, 'message' => 'File uploaded successfully'];
    }

    return ['success' => false, 'error' => "File upload failed: HTTP $httpCode", 'response' => $response];
}
