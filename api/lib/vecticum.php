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
        // Don't fetch the full invoice list — it can be huge/slow and cause timeouts.
        // A successful auth + a quick HEAD on the class endpoint is enough.
        if (!empty($company['vecticum_company_id'])) {
            // Use Currency endpoint (always small) to verify the token has access
            $ch = curl_init($company['vecticum_api_base_url'] . '/KU5VRy3VdyQP75UpHqpb');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Accept: application/json', "Authorization: Bearer $token"],
                CURLOPT_TIMEOUT => 10,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                $errData = json_decode($response, true);
                $errMsg = $errData['message'] ?? "HTTP $httpCode";
                return ['success' => false, 'error' => "Authenticated but API access failed: $errMsg"];
            }
            return ['success' => true, 'message' => 'Connected and authenticated successfully'];
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

function findVecticumCurrency($company, $currencyCode, $token = null) {
    if (!$token) $token = getVecticumToken($company);
    $currencyCode = strtoupper(trim($currencyCode));

    $url = $company['vecticum_api_base_url'] . '/onP41CBuLz8oiwokgLWb';
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
    $currencies = json_decode($response, true);
    if (!is_array($currencies)) return null;

    foreach ($currencies as $c) {
        if (strtoupper(trim($c['name'] ?? '')) === $currencyCode) {
            return ['id' => $c['id'], 'name' => $c['name']];
        }
    }
    return null;
}

function getVecticumDefaultAuthor($company, $token = null) {
    if (!$token) $token = getVecticumToken($company);

    $url = $company['vecticum_api_base_url'] . '/_inboxSetup';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json', "Authorization: Bearer $token"],
        CURLOPT_TIMEOUT => 10,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) return null;
    $inboxes = json_decode($response, true);
    if (!is_array($inboxes)) return null;

    // Find the first inbox with a defaultAuthor set
    foreach ($inboxes as $inbox) {
        if (!empty($inbox['defaultAuthor']['id'])) {
            return ['id' => $inbox['defaultAuthor']['id'], 'name' => $inbox['defaultAuthor']['name'] ?? ''];
        }
    }
    return null;
}

function normalizeVecticumDate($value) {
    $value = trim((string)$value);
    if ($value === '') return '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value, $m)) {
        return $m[0];
    }
    $ts = strtotime($value);
    return $ts ? date('Y-m-d', $ts) : '';
}

function normalizeVecticumInvoiceNo($value) {
    $value = strtoupper(trim((string)$value));
    $value = preg_replace('/\s+/', '', $value);
    return preg_replace('/[^A-Z0-9\/\-_\.]/', '', $value);
}

function normalizeVecticumCounterpartyText($value) {
    $value = _stripDiacritics(strtolower(trim((string)$value)));
    $value = preg_replace('/\b(uab|ab|mb|vsi|ii|bv|gmbh|ltd|llc|s\.?a\.?|srl)\b/i', '', $value);
    $value = preg_replace('/[^a-z0-9]/', '', $value);
    return $value;
}

function normalizeVecticumCounterpartyCode($value) {
    return preg_replace('/[^A-Z0-9]/', '', strtoupper(trim((string)$value)));
}

function buildVecticumCounterpartyCandidates($record) {
    $candidates = [];
    $counterparty = is_array($record['counterparty'] ?? null) ? $record['counterparty'] : [];

    $add = function ($value, $type = 'code') use (&$candidates) {
        $normalized = $type === 'text' ? normalizeVecticumCounterpartyText($value) : normalizeVecticumCounterpartyCode($value);
        if ($normalized !== '') $candidates[$type . ':' . $normalized] = true;
    };

    if (!empty($counterparty['id'])) {
        $candidates['id:' . trim((string)$counterparty['id'])] = true;
    }

    foreach ([
        $record['counterpartyCode'] ?? null,
        $record['counterpartyVatCode'] ?? null,
        $record['counterpartyCompanyCode'] ?? null,
        $counterparty['vatNumber'] ?? null,
        $counterparty['companyCode'] ?? null,
        $counterparty['code'] ?? null,
    ] as $value) {
        $add($value, 'code');
    }

    foreach ([
        $record['counterpartyName'] ?? null,
        $counterparty['name'] ?? null,
    ] as $value) {
        $add($value, 'text');
    }

    return array_keys($candidates);
}

function findExistingVecticumInvoice($company, $metadata, $partner = null, $token = null) {
    if (!$token) $token = getVecticumToken($company);

    $expectedInvoiceNo = normalizeVecticumInvoiceNo($metadata['invoiceNumber'] ?? '');
    $expectedInvoiceDate = normalizeVecticumDate($metadata['invoiceDate'] ?? '');
    if ($expectedInvoiceNo === '' || $expectedInvoiceDate === '') {
        return null;
    }

    $expectedCounterparty = [];
    if (!empty($partner['id'])) {
        $expectedCounterparty['id:' . trim((string)$partner['id'])] = true;
    }
    if (!empty($metadata['vendorVatId'])) {
        $code = normalizeVecticumCounterpartyCode($metadata['vendorVatId']);
        if ($code !== '') $expectedCounterparty['code:' . $code] = true;
    }
    if (!empty($partner['name'])) {
        $name = normalizeVecticumCounterpartyText($partner['name']);
        if ($name !== '') $expectedCounterparty['text:' . $name] = true;
    }
    if (!empty($metadata['vendorName'])) {
        $name = normalizeVecticumCounterpartyText($metadata['vendorName']);
        if ($name !== '') $expectedCounterparty['text:' . $name] = true;
    }

    if (empty($expectedCounterparty)) {
        return null;
    }

    $url = $company['vecticum_api_base_url'] . '/' . $company['vecticum_company_id'];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json', "Authorization: Bearer $token"],
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        return null;
    }

    $records = json_decode($response, true);
    if (!is_array($records)) {
        return null;
    }

    foreach ($records as $record) {
        if (!is_array($record)) continue;
        if (normalizeVecticumInvoiceNo($record['invoiceNo'] ?? '') !== $expectedInvoiceNo) continue;
        if (normalizeVecticumDate($record['invoiceDate'] ?? '') !== $expectedInvoiceDate) continue;

        $recordCounterparties = buildVecticumCounterpartyCandidates($record);
        if (!$recordCounterparties) continue;

        foreach ($recordCounterparties as $candidate) {
            if (isset($expectedCounterparty[$candidate])) {
                return $record;
            }
        }
    }

    return null;
}

function isVecticumUniquenessError($message) {
    return (bool)preg_match('/uniqueness validation failed|document already exists|already exists|duplicate/i', (string)$message);
}

function createVecticumRecord($company, $body, $token) {
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

    $data = json_decode($response, true);
    if ($httpCode < 200 || $httpCode >= 300) {
        return [
            'success' => false,
            'httpCode' => $httpCode,
            'error' => $data['message'] ?? "Vecticum API error: HTTP $httpCode",
            'raw' => $data,
        ];
    }

    return ['success' => true, 'data' => $data];
}

function getEcbExchangeRate($currencyCode, $invoiceDate) {
    $currency = strtoupper(trim((string)$currencyCode));
    if ($currency === '' || $currency === 'EUR') {
        return null;
    }

    $date = trim((string)$invoiceDate);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return null;
    }

    $url = 'https://data-api.ecb.europa.eu/service/data/EXR/D.' . rawurlencode($currency) . '.EUR.SP00.A'
        . '?startPeriod=' . rawurlencode($date)
        . '&endPeriod=' . rawurlencode($date)
        . '&format=csvdata';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Accept: text/csv'],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        return null;
    }

    $lines = preg_split("/\r\n|\n|\r/", trim($response));
    if (!$lines || count($lines) < 2 || empty($lines[1])) {
        return null;
    }

    $columns = str_getcsv($lines[1]);
    if (!isset($columns[7]) || !is_numeric($columns[7])) {
        return null;
    }

    return number_format((float)$columns[7], 4, '.', '');
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

        // Set document type booleans
        $docType = strtolower(trim($metadata['documentType'] ?? ''));
        if ($docType === 'proforma' || strpos($docType, 'pro forma') !== false || strpos($docType, 'pro-forma') !== false) {
            $body['proFormaInvoice'] = true;
        }
        if ($docType === 'credit_note' || $docType === 'credit note' || strpos($docType, 'credit') !== false) {
            $body['creditInvoice'] = true;
        }

        // Set "from" field to sender email
        if (!empty($metadata['_senderEmail'])) {
            $body['from'] = $metadata['_senderEmail'];
        }

        if (!empty($metadata['vendorVatId'])) {
            $body['counterpartyCode'] = $metadata['vendorVatId'];
        }

        $currency = strtoupper(trim($metadata['currency'] ?? 'EUR'));
        $currencyRef = findVecticumCurrency($company, $currency, $token);
        if ($currencyRef) {
            $body['currency'] = $currencyRef;
        }
        $exchangeRate = getEcbExchangeRate($currency, $metadata['invoiceDate'] ?? null);
        if ($exchangeRate !== null) {
            $body['exchangeRate'] = $exchangeRate;
        }

        // Match author by sender email, fall back to inbox defaultAuthor
        $author = null;
        if (!empty($metadata['_senderEmail'])) {
            $author = findVecticumAuthor($company, $metadata['_senderEmail'], $token);
        }
        if (!$author) {
            // Fetch defaultAuthor from Vecticum inbox setup
            $author = getVecticumDefaultAuthor($company, $token);
        }
        if ($author) {
            $body['author'] = $author;
        }

        // Match partner/counterparty
        $partner = findVecticumPartner($company, $metadata['vendorVatId'] ?? '', $metadata['vendorName'] ?? '', $token);
        if ($partner) {
            $body['counterparty'] = $partner;
        }

        $existingRecord = findExistingVecticumInvoice($company, $metadata, $partner, $token);
        if ($existingRecord) {
            $existingRef = $existingRecord['invoiceNo'] ?? ($existingRecord['id'] ?? 'existing record');
            return ['success' => false, 'error' => "Invoice already exists in Vecticum for the same invoice date, invoice number, and counterparty ({$existingRef})"];
        }

        // Remove null values
        $body = array_filter($body, fn($v) => $v !== null);

        $createResult = createVecticumRecord($company, $body, $token);
        if (!$createResult['success']) {
            $errorMsg = $createResult['error'] ?? 'Vecticum API error';
            $canRetryWithoutInvoiceNo = !empty($body['invoiceNo']) && isVecticumUniquenessError($errorMsg);
            if ($canRetryWithoutInvoiceNo && !$existingRecord) {
                $retryBody = $body;
                unset($retryBody['invoiceNo']);
                if (empty($retryBody['description']) && !empty($metadata['invoiceNumber'])) {
                    $retryBody['description'] = 'Original invoice number: ' . $metadata['invoiceNumber'];
                }

                $retryResult = createVecticumRecord($company, $retryBody, $token);
                if ($retryResult['success']) {
                    $data = $retryResult['data'] ?? [];
                    $externalId = $data['id'] ?? null;

                    if ($externalId && !empty($metadata['_filePath']) && file_exists($metadata['_filePath'])) {
                        $fileResult = uploadFileToVecticum($company, $externalId, $metadata['_filePath'], $metadata['_fileName'] ?? 'invoice.pdf', $token);
                        return [
                            'success' => true,
                            'externalId' => $externalId,
                            'fileUpload' => $fileResult,
                            'fallbackWithoutInvoiceNo' => true,
                        ];
                    }

                    return [
                        'success' => true,
                        'externalId' => $externalId,
                        'fallbackWithoutInvoiceNo' => true,
                    ];
                }

                $errorMsg = ($retryResult['error'] ?? 'Vecticum API error') . ' (retry without invoice number also failed)';
            }

            return ['success' => false, 'error' => $errorMsg];
        }

        $data = $createResult['data'] ?? [];
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

function uploadAdditionalFileToVecticum($company, $documentId, $filePath, $fileName, $token = null) {
    if (!$token) $token = getVecticumToken($company);
    if (!file_exists($filePath)) return ['success' => false, 'error' => 'File not found'];

    $mimeType = function_exists('mime_content_type') ? (mime_content_type($filePath) ?: 'application/octet-stream') : 'application/pdf';
    $url = $company['vecticum_api_base_url'] . '/files/' . $company['vecticum_company_id'] . '/' . $documentId . '/additionalFiles';
    $cfile = new \CURLFile($filePath, $mimeType, $fileName);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [$fileName => $cfile],
        CURLOPT_HTTPHEADER => ['Accept: application/json', "Authorization: Bearer $token"],
        CURLOPT_TIMEOUT => 60,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 201) return ['success' => true];
    return ['success' => false, 'error' => "Additional file upload failed: HTTP $httpCode"];
}
