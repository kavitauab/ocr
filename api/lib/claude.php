<?php

// All available extraction fields
function getAllExtractionFields() {
    return [
        'documentType' => ['type' => 'string', 'enum' => ['invoice', 'proforma', 'credit_note'], 'description' => 'Type of document: "invoice" for standard/commercial invoices, tax invoices, and paid invoice receipts/confirmations that represent the actual invoice; "proforma" only for genuine proforma/advance/prepayment/deposit invoices; "credit_note" for credit notes (kreditinė sąskaita faktūra)'],
        'invoiceNumber' => ['type' => 'string', 'description' => 'The invoice number/ID. If the document shows a series and number separately (e.g. "Serija TE2023 Nr. 285"), combine them with a dash (e.g. "TE2023-285"). Remove prefixes like "Nr.", "No.", "Serija" etc. If the invoice number is already a single value (e.g. "SJ-152138"), keep it as-is.'],
        'invoiceDate' => ['type' => 'string', 'description' => 'Invoice issue date in YYYY-MM-DD format'],
        'dueDate' => ['type' => ['string', 'null'], 'description' => 'Payment due date in YYYY-MM-DD format, or null if not stated'],
        'vendorName' => ['type' => 'string', 'description' => 'Name of the company/person issuing the invoice'],
        'vendorAddress' => ['type' => ['string', 'null'], 'description' => 'Full address of the vendor'],
        'vendorVatId' => ['type' => ['string', 'null'], 'description' => 'VAT/tax ID of the vendor'],
        'buyerName' => ['type' => ['string', 'null'], 'description' => 'Name of the buyer/recipient'],
        'buyerAddress' => ['type' => ['string', 'null'], 'description' => 'Full address of the buyer'],
        'buyerVatId' => ['type' => ['string', 'null'], 'description' => 'VAT/tax ID of the buyer'],
        'subtotalAmount' => ['type' => ['number', 'null'], 'description' => 'Amount explicitly labeled Sub Total/Subtotal, before freight/shipping/insurance/handling/other charges and before tax if shown separately'],
        'taxAmount' => ['type' => ['number', 'null'], 'description' => 'Total tax/VAT amount, or null when not shown'],
        'totalAmount' => ['type' => 'number', 'description' => 'Final payable amount explicitly labeled Total/Grand Total/Amount Due/Balance Due, after freight/shipping/insurance/handling/other charges and tax. Never use Sub Total/Subtotal here when a final total exists'],
        'currency' => ['type' => 'string', 'description' => 'Three-letter currency code (EUR, USD, GBP, etc.)'],
        'poNumber' => ['type' => ['string', 'null'], 'description' => 'Purchase order number, or null if not present'],
        'paymentTerms' => ['type' => ['string', 'null'], 'description' => 'Payment terms, or null if not stated'],
        'bankDetails' => ['type' => ['string', 'null'], 'description' => 'Bank/payment details including IBAN, account number, etc.'],
    ];
}

function buildClaudeUsageMetadata($responseData, $fallbackModel) {
    $usage = (isset($responseData['usage']) && is_array($responseData['usage'])) ? $responseData['usage'] : [];

    $inputTokens = (int)($usage['input_tokens'] ?? 0);
    $outputTokens = (int)($usage['output_tokens'] ?? 0);
    $cacheCreationInputTokens = (int)($usage['cache_creation_input_tokens'] ?? 0);
    $cacheReadInputTokens = (int)($usage['cache_read_input_tokens'] ?? 0);

    return [
        'provider' => 'anthropic',
        'model' => $responseData['model'] ?? $fallbackModel,
        'requestId' => $responseData['id'] ?? null,
        'inputTokens' => $inputTokens,
        'outputTokens' => $outputTokens,
        'cacheCreationInputTokens' => $cacheCreationInputTokens,
        'cacheReadInputTokens' => $cacheReadInputTokens,
        'totalTokens' => $inputTokens + $outputTokens + $cacheCreationInputTokens + $cacheReadInputTokens,
    ];
}

function mergeClaudeUsageMetadata($baseUsage, $extraUsage) {
    if (!$baseUsage) return $extraUsage;
    if (!$extraUsage) return $baseUsage;

    foreach ([
        'inputTokens',
        'outputTokens',
        'cacheCreationInputTokens',
        'cacheReadInputTokens',
        'totalTokens',
    ] as $key) {
        $baseUsage[$key] = (int)($baseUsage[$key] ?? 0) + (int)($extraUsage[$key] ?? 0);
    }

    if (empty($baseUsage['requestId']) && !empty($extraUsage['requestId'])) {
        $baseUsage['requestId'] = $extraUsage['requestId'];
    }
    if (empty($baseUsage['model']) && !empty($extraUsage['model'])) {
        $baseUsage['model'] = $extraUsage['model'];
    }

    return $baseUsage;
}

function getNumericExtractionValue($value) {
    if ($value === null || $value === '') return null;
    if (!is_numeric($value)) return null;
    return round((float)$value, 4);
}

function shouldRecheckInvoiceTotals($extracted) {
    $subtotal = getNumericExtractionValue($extracted['subtotalAmount'] ?? null);
    $total = getNumericExtractionValue($extracted['totalAmount'] ?? null);
    if ($subtotal === null || $total === null) {
        return false;
    }

    return abs($total - $subtotal) < 0.01 || $total < ($subtotal - 0.01);
}

function reviewInvoiceMonetarySummary($filePath, $fileType, $model) {
    $fileData = file_get_contents($filePath);
    if ($fileData === false) {
        throw new Exception('Cannot read file: ' . $filePath);
    }
    $base64Data = base64_encode($fileData);

    $contentBlocks = [];
    if ($fileType === 'pdf') {
        $contentBlocks[] = [
            'type' => 'document',
            'source' => [
                'type' => 'base64',
                'media_type' => 'application/pdf',
                'data' => $base64Data,
            ],
        ];
    } else {
        $mediaType = $fileType === 'png' ? 'image/png' : 'image/jpeg';
        $contentBlocks[] = [
            'type' => 'image',
            'source' => [
                'type' => 'base64',
                'media_type' => $mediaType,
                'data' => $base64Data,
            ],
        ];
    }

    $contentBlocks[] = [
        'type' => 'text',
        'text' => 'Re-check only the monetary summary and return the final payable total, subtotal, tax, and any extra freight/shipping/insurance charges.',
    ];

    $tool = [
        'name' => 'save_invoice_amounts',
        'description' => 'Return only the monetary summary fields from the invoice.',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'subtotalAmount' => ['type' => ['number', 'null']],
                'taxAmount' => ['type' => ['number', 'null']],
                'shippingAmount' => ['type' => ['number', 'null']],
                'insuranceAmount' => ['type' => ['number', 'null']],
                'otherChargesAmount' => ['type' => ['number', 'null']],
                'totalAmount' => ['type' => 'number'],
                'currency' => ['type' => ['string', 'null']],
                'confidence' => [
                    'type' => 'object',
                    'properties' => [
                        'subtotalAmount' => ['type' => 'number'],
                        'taxAmount' => ['type' => 'number'],
                        'shippingAmount' => ['type' => 'number'],
                        'insuranceAmount' => ['type' => 'number'],
                        'otherChargesAmount' => ['type' => 'number'],
                        'totalAmount' => ['type' => 'number'],
                    ],
                    'required' => ['totalAmount'],
                    'additionalProperties' => false,
                ],
            ],
            'required' => ['totalAmount', 'confidence'],
            'additionalProperties' => false,
        ],
    ];

    $requestBody = [
        'model' => $model,
        'max_tokens' => 1024,
        'system' => [[
            'type' => 'text',
            'text' => "You are validating invoice monetary totals.

Rules:
1. Distinguish carefully between Sub Total/Subtotal and final Total/Grand Total/Amount Due/Balance Due.
2. Never return a value labeled Sub Total/Subtotal as totalAmount if a later final Total/Grand Total exists.
3. Include freight, shipping, insurance, handling, and other charges in totalAmount when they are part of the final payable amount.
4. If the summary shows subtotal + extra charges + tax = total, totalAmount must be that final total.
5. Return plain numbers only, without currency symbols or thousand separators.
6. Use null for any component that is not shown.
7. Provide an honest confidence score for each returned amount.",
            'cache_control' => ['type' => 'ephemeral'],
        ]],
        'tools' => [$tool],
        'tool_choice' => ['type' => 'tool', 'name' => 'save_invoice_amounts'],
        'messages' => [
            ['role' => 'user', 'content' => $contentBlocks],
        ],
    ];

    $apiKey = getAnthropicApiKey();
    if (empty($apiKey)) {
        throw new Exception('Anthropic API key not configured. Set it in System Settings or api/.env');
    }

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($requestBody),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_TIMEOUT => 120,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        throw new Exception('Claude API request failed: ' . $curlError);
    }
    if ($httpCode !== 200) {
        $errorData = json_decode($response, true);
        $errorMsg = $errorData['error']['message'] ?? "HTTP $httpCode";
        throw new Exception('Claude API error: ' . $errorMsg);
    }

    $data = json_decode($response, true);
    if (!$data || !isset($data['content'])) {
        throw new Exception('Claude API returned invalid response');
    }

    foreach ($data['content'] as $block) {
        if ($block['type'] === 'tool_use') {
            return [
                'data' => $block['input'],
                'usage' => buildClaudeUsageMetadata($data, $model),
            ];
        }
    }

    throw new Exception('Claude API returned no monetary validation payload');
}

function maybeRefineInvoiceTotals($filePath, $fileType, $extracted, $model) {
    if (!shouldRecheckInvoiceTotals($extracted)) {
        return ['data' => $extracted, 'usage' => null, 'adjusted' => false];
    }

    $review = reviewInvoiceMonetarySummary($filePath, $fileType, $model);
    $refined = $review['data'] ?? [];
    $refinedTotal = getNumericExtractionValue($refined['totalAmount'] ?? null);
    $refinedSubtotal = getNumericExtractionValue($refined['subtotalAmount'] ?? null);
    $refinedTax = getNumericExtractionValue($refined['taxAmount'] ?? null);
    $refinedShipping = getNumericExtractionValue($refined['shippingAmount'] ?? null) ?? 0.0;
    $refinedInsurance = getNumericExtractionValue($refined['insuranceAmount'] ?? null) ?? 0.0;
    $refinedOther = getNumericExtractionValue($refined['otherChargesAmount'] ?? null) ?? 0.0;
    $refinedConfidence = (float)($refined['confidence']['totalAmount'] ?? 0);
    $originalTotal = getNumericExtractionValue($extracted['totalAmount'] ?? null) ?? 0.0;
    $originalSubtotal = getNumericExtractionValue($extracted['subtotalAmount'] ?? null);

    $componentsTotal = ($refinedSubtotal ?? 0.0) + ($refinedTax ?? 0.0) + $refinedShipping + $refinedInsurance + $refinedOther;
    $componentsSupport = $refinedSubtotal !== null && abs($componentsTotal - (float)$refinedTotal) < 0.05;
    $sameSubtotal = $originalSubtotal === null || $refinedSubtotal === null || abs($originalSubtotal - $refinedSubtotal) < 0.05;
    $betterTotal = $refinedTotal !== null && $refinedTotal > ($originalTotal + 0.01);

    if ($refinedTotal === null || $refinedConfidence < 0.85 || !$sameSubtotal || (!$componentsSupport && !$betterTotal)) {
        return ['data' => $extracted, 'usage' => $review['usage'] ?? null, 'adjusted' => false];
    }

    $adjusted = $extracted;
    $adjusted['totalAmount'] = $refinedTotal;
    if ($refinedSubtotal !== null) {
        $adjusted['subtotalAmount'] = $refinedSubtotal;
    }
    if ($refinedTax !== null) {
        $adjusted['taxAmount'] = $refinedTax;
    }
    if (!empty($refined['currency'])) {
        $adjusted['currency'] = $refined['currency'];
    }
    if (!isset($adjusted['confidence']) || !is_array($adjusted['confidence'])) {
        $adjusted['confidence'] = [];
    }
    $adjusted['confidence']['totalAmount'] = $refinedConfidence;
    if (isset($refined['confidence']['subtotalAmount']) && $refinedSubtotal !== null) {
        $adjusted['confidence']['subtotalAmount'] = (float)$refined['confidence']['subtotalAmount'];
    }
    if (isset($refined['confidence']['taxAmount']) && $refinedTax !== null) {
        $adjusted['confidence']['taxAmount'] = (float)$refined['confidence']['taxAmount'];
    }

    return ['data' => $adjusted, 'usage' => $review['usage'] ?? null, 'adjusted' => true];
}

function normalizeDocumentType($documentType, $context = []) {
    $normalized = strtolower(trim((string)$documentType));
    if ($normalized === '') {
        return null;
    }

    $normalized = str_replace([' ', '-'], '_', $normalized);
    if ($normalized === 'credit') {
        $normalized = 'credit_note';
    }
    if ($normalized === 'pro_forma') {
        $normalized = 'proforma';
    }

    $contextParts = [];
    foreach (['detail', 'invoiceNumber', 'paymentTerms', 'vendorName', 'buyerName'] as $field) {
        if (!empty($context[$field])) {
            $contextParts[] = (string)$context[$field];
        }
    }
    $contextText = strtolower(implode(' ', $contextParts));

    $hasExplicitProforma = preg_match('/\b(pro[\s-]?forma|advance invoice|prepayment|deposit invoice|down payment)\b/', $contextText) === 1;
    $hasPaidInvoiceHint = preg_match('/\b(paid|payment receipt|payment confirmation|payment #|tax invoice|invoice)\b/', $contextText) === 1;

    if ($normalized === 'proforma') {
        if ($hasExplicitProforma) {
            return 'proforma';
        }
        if ($hasPaidInvoiceHint && !empty($context['invoiceNumber'])) {
            return 'invoice';
        }
    }

    if ($normalized === 'invoice' && $hasExplicitProforma) {
        return 'proforma';
    }

    return $normalized;
}

function extractInvoiceData($filePath, $fileType, $enabledFields = null, $includeUsage = false) {
    // Two-tier extraction: try cheap model first, escalate if low confidence
    $smartExtraction = getSetting('smart_extraction', '1') === '1';
    $primaryModel = getSetting('extraction_model', 'claude-sonnet-4-6');
    $cheapModel = getSetting('extraction_model_fast', 'claude-haiku-4-5-20251001');
    $confidenceThreshold = floatval(getSetting('extraction_confidence_threshold', '0.9'));

    if ($smartExtraction && $cheapModel && $cheapModel !== $primaryModel) {
        // Try cheap model first
        $cheapResult = _callExtractionApi($filePath, $fileType, $enabledFields, $cheapModel);
        $extracted = $cheapResult['data'] ?? $cheapResult;
        $cheapUsage = $cheapResult['usage'] ?? null;

        // Critical fields for escalation — configurable via system settings.
        // Only these fields trigger escalation to the better model when uncertain.
        $criticalFieldsSetting = getSetting('critical_fields', 'invoiceNumber,vendorName,totalAmount,currency');
        $criticalFields = array_filter(array_map('trim', explode(',', $criticalFieldsSetting)));
        if (empty($criticalFields)) {
            $criticalFields = ['invoiceNumber', 'vendorName', 'totalAmount', 'currency'];
        }
        $confidences = $extracted['confidence'] ?? [];
        $failedFields = [];
        foreach ($criticalFields as $field) {
            if (!isset($confidences[$field])) continue;
            $s = floatval($confidences[$field]);
            if ($s < $confidenceThreshold) {
                // Only escalate if the field has a value (not missing)
                $value = $extracted[$field] ?? null;
                if ($value !== null && $value !== '') {
                    $failedFields[$field] = $s;
                }
            }
        }

        if (empty($failedFields)) {
            $refined = maybeRefineInvoiceTotals($filePath, $fileType, $extracted, $primaryModel);
            $extracted = $refined['data'];
            $cheapUsage = mergeClaudeUsageMetadata($cheapUsage, $refined['usage'] ?? null);
            if (!$includeUsage) return $extracted;
            return ['data' => $extracted, 'usage' => $cheapUsage, 'model_used' => $cheapModel, 'escalated' => false, 'escalation_reason' => null];
        }

        // Low confidence on actual values — escalate to primary model
        $escalationReason = array_map(fn($f, $s) => "$f:" . round($s * 100) . "%", array_keys($failedFields), array_values($failedFields));
        error_log("[OCR Smart] Escalating to $primaryModel — failed fields: " . implode(', ', $escalationReason));
        $primaryResult = _callExtractionApi($filePath, $fileType, $enabledFields, $primaryModel);
        $primaryExtracted = $primaryResult['data'] ?? $primaryResult;
        $primaryUsage = $primaryResult['usage'] ?? null;

        // Merge usage (sum tokens from both calls)
        if ($cheapUsage && $primaryUsage) {
            $primaryUsage = mergeClaudeUsageMetadata($primaryUsage, $cheapUsage);
        }

        $refined = maybeRefineInvoiceTotals($filePath, $fileType, $primaryExtracted, $primaryModel);
        $primaryExtracted = $refined['data'];
        $primaryUsage = mergeClaudeUsageMetadata($primaryUsage, $refined['usage'] ?? null);

        if (!$includeUsage) return $primaryExtracted;
        return ['data' => $primaryExtracted, 'usage' => $primaryUsage, 'model_used' => $primaryModel, 'escalated' => true, 'escalation_reason' => implode(', ', $escalationReason)];
    }

    // No smart extraction — use primary model directly
    $result = _callExtractionApi($filePath, $fileType, $enabledFields, $primaryModel, true);
    $extracted = $result['data'] ?? $result;
    $usage = $result['usage'] ?? null;
    $refined = maybeRefineInvoiceTotals($filePath, $fileType, $extracted, $primaryModel);
    $extracted = $refined['data'];
    $usage = mergeClaudeUsageMetadata($usage, $refined['usage'] ?? null);

    if (!$includeUsage) return $extracted;
    return ['data' => $extracted, 'usage' => $usage, 'model_used' => $primaryModel, 'escalated' => false, 'escalation_reason' => null];
}

function _callExtractionApi($filePath, $fileType, $enabledFields, $model, $includeUsage = true) {
    $fileData = file_get_contents($filePath);
    if ($fileData === false) {
        throw new Exception('Cannot read file: ' . $filePath);
    }
    $base64Data = base64_encode($fileData);

    // Build content blocks
    $contentBlocks = [];

    if ($fileType === 'pdf') {
        $contentBlocks[] = [
            'type' => 'document',
            'source' => [
                'type' => 'base64',
                'media_type' => 'application/pdf',
                'data' => $base64Data,
            ],
        ];
    } else {
        $mediaType = $fileType === 'png' ? 'image/png' : 'image/jpeg';
        $contentBlocks[] = [
            'type' => 'image',
            'source' => [
                'type' => 'base64',
                'media_type' => $mediaType,
                'data' => $base64Data,
            ],
        ];
    }

    $contentBlocks[] = [
        'type' => 'text',
        'text' => 'Extract all invoice data from this document. Use the save_invoice_data tool to return the structured results.',
    ];

    $systemPrompt = "You are an expert invoice data extraction system. Your job is to carefully analyze invoice documents and extract structured data with high accuracy.

Rules:
1. Extract ALL visible information from the invoice. Do not guess or fabricate data.
2. If a field is not present or not readable in the document, use null for that field.
3. Convert all dates to YYYY-MM-DD format.
4. Convert all monetary amounts to plain numbers (no currency symbols or thousand separators). Use the decimal point for cents.
5. Identify the currency from the document context (symbols like €, \$, £ or explicit text).
6. Provide an honest confidence score (0.0 to 1.0) for each field:
   - 1.0 = clearly printed, unambiguous, high certainty
   - 0.7-0.9 = readable but slightly unclear
   - 0.5-0.7 = uncertain, had to interpret or infer
   - Below 0.5 = very uncertain, could easily be wrong
7. If the document is not an invoice, still try to extract whatever relevant data you can, but set confidence scores low.
8. For multi-page documents, examine ALL pages.
9. Do not label a document as proforma unless it explicitly says proforma, advance invoice, prepayment, or deposit invoice. A paid invoice/payment receipt that represents the actual invoice should still be classified as invoice.
10. Distinguish Sub Total/Subtotal from the final Total/Grand Total/Amount Due. totalAmount must be the final payable total, not the subtotal.
11. If the document shows freight, shipping, insurance, handling, or other charges between subtotal and total, include them in totalAmount.";

    // Build tool properties based on enabled fields
    $allFields = getAllExtractionFields();

    // Filter to enabled fields only (null = all fields)
    if ($enabledFields !== null && is_array($enabledFields) && !empty($enabledFields)) {
        // Always include confidence
        $filteredProperties = [];
        $filteredConfidence = [];
        foreach ($enabledFields as $fieldKey) {
            if (isset($allFields[$fieldKey])) {
                $filteredProperties[$fieldKey] = $allFields[$fieldKey];
                $filteredConfidence[$fieldKey] = ['type' => 'number'];
            }
        }
        $properties = $filteredProperties;
        $confidenceProps = $filteredConfidence;
        // Required: only fields that exist in enabled set
        $defaultRequired = ['documentType', 'invoiceNumber', 'vendorName', 'totalAmount', 'currency'];
        $required = array_values(array_intersect($defaultRequired, $enabledFields));
        $required[] = 'confidence';

        $systemPrompt .= "\n10. Only extract these specific fields: " . implode(', ', $enabledFields) . ". Ignore all other fields.";
    } else {
        $properties = $allFields;
        $confidenceProps = [];
        foreach (array_keys($allFields) as $k) {
            $confidenceProps[$k] = ['type' => 'number'];
        }
        $required = ['documentType', 'invoiceNumber', 'vendorName', 'totalAmount', 'currency', 'confidence'];
    }

    $properties['confidence'] = [
        'type' => 'object',
        'description' => 'Confidence score (0.0 to 1.0) for each extracted field',
        'properties' => $confidenceProps,
    ];

    $tool = [
        'name' => 'save_invoice_data',
        'description' => 'Save the extracted invoice data. Call this tool with all extracted fields from the invoice document.',
        'input_schema' => [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
            'additionalProperties' => false,
        ],
    ];

    $requestBody = [
        'model' => $model,
        'max_tokens' => 4096,
        'system' => [['type' => 'text', 'text' => $systemPrompt, 'cache_control' => ['type' => 'ephemeral']]],
        'tools' => [$tool],
        'tool_choice' => ['type' => 'tool', 'name' => 'save_invoice_data'],
        'messages' => [
            ['role' => 'user', 'content' => $contentBlocks],
        ],
    ];

    $apiKey = getAnthropicApiKey();
    if (empty($apiKey)) {
        throw new \Exception('Anthropic API key not configured. Set it in System Settings or api/.env');
    }

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($requestBody),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_TIMEOUT => 120,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        throw new Exception('Claude API request failed: ' . $curlError);
    }

    if ($httpCode !== 200) {
        $errorData = json_decode($response, true);
        $errorMsg = $errorData['error']['message'] ?? "HTTP $httpCode";
        throw new Exception('Claude API error: ' . $errorMsg);
    }

    $data = json_decode($response, true);
    if (!$data || !isset($data['content'])) {
        throw new Exception('Claude API returned invalid response');
    }

    $usageMetadata = buildClaudeUsageMetadata($data, $model);

    // Find the tool_use block
    foreach ($data['content'] as $block) {
        if ($block['type'] === 'tool_use') {
            if (!$includeUsage) {
                $result = $block['input'];
                if (isset($result['documentType'])) {
                    $result['documentType'] = normalizeDocumentType($result['documentType'], $result);
                }
                return $result;
            }

            return [
                'data' => $block['input'],
                'usage' => $usageMetadata,
                'model_used' => $model,
                'escalated' => false,
            ];
        }
    }

    throw new Exception('Claude did not return structured extraction data');
}

/**
 * Classify a document type using Haiku (cheap, fast).
 * Returns: ['category' => string, 'detail' => string, 'confidence' => float, 'usage' => array]
 */
function classifyDocument($filePath, $fileType) {
    $fileData = file_get_contents($filePath);
    if ($fileData === false) throw new Exception('Cannot read file: ' . $filePath);

    // For PDFs, extract only the first page to save tokens
    if ($fileType === 'pdf') {
        // Use page limit via the API's pages parameter — send full PDF but tell Claude to only look at page 1
        $base64Data = base64_encode($fileData);
        $contentBlocks = [
            ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $base64Data]],
        ];
    } else {
        $base64Data = base64_encode($fileData);
        $mediaType = $fileType === 'png' ? 'image/png' : 'image/jpeg';
        $contentBlocks = [
            ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mediaType, 'data' => $base64Data]],
        ];
    }
    $contentBlocks[] = ['type' => 'text', 'text' => 'Classify this document based on the first page only. What type of business document is this?'];

    $tool = [
        'name' => 'classify_document',
        'description' => 'Classify the document type.',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'category' => [
                    'type' => 'string',
                    'enum' => ['invoice', 'proforma', 'credit_note', 'order_confirmation', 'act', 'report', 'contract', 'other'],
                    'description' => 'Document category: "invoice" for standard/commercial invoices and paid invoice receipts/confirmations that represent the actual invoice, "proforma" only for genuine proforma/advance/prepayment/deposit invoices, "credit_note" for credit notes, "order_confirmation" for order confirmations/purchase orders, "act" for work acceptance acts (darbų priėmimo-perdavimo aktas) or service acts, "report" for reports, "contract" for contracts/agreements, "other" for anything else',
                ],
                'detail' => [
                    'type' => 'string',
                    'description' => 'Short description of the document, e.g. "work acceptance act", "service contract", "VAT invoice"',
                ],
                'confidence' => [
                    'type' => 'number',
                    'description' => 'Confidence in classification (0.0-1.0)',
                ],
            ],
            'required' => ['category', 'detail', 'confidence'],
        ],
    ];

    $model = getSetting('classification_model', 'claude-haiku-4-5-20251001');

    $requestBody = [
        'model' => $model,
        'max_tokens' => 256,
        'system' => [['type' => 'text', 'text' => 'You are a document classifier. Identify the type of business document shown. Be precise: only use proforma for explicit proforma/advance/prepayment/deposit invoices. A paid invoice or payment receipt that represents the actual invoice should be classified as invoice, not proforma.', 'cache_control' => ['type' => 'ephemeral']]],
        'tools' => [$tool],
        'tool_choice' => ['type' => 'tool', 'name' => 'classify_document'],
        'messages' => [['role' => 'user', 'content' => $contentBlocks]],
    ];

    $apiKey = getAnthropicApiKey();
    if (empty($apiKey)) throw new Exception('Anthropic API key not configured');

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($requestBody),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        $errorData = json_decode($response, true);
        throw new Exception('Classification failed: ' . ($errorData['error']['message'] ?? "HTTP $httpCode"));
    }

    $data = json_decode($response, true);
    $usage = buildClaudeUsageMetadata($data, $model);

    foreach ($data['content'] ?? [] as $block) {
        if ($block['type'] === 'tool_use') {
            $category = normalizeDocumentType($block['input']['category'] ?? 'other', [
                'detail' => $block['input']['detail'] ?? '',
            ]);
            return [
                'category' => $category ?? 'other',
                'detail' => $block['input']['detail'] ?? '',
                'confidence' => $block['input']['confidence'] ?? 0,
                'usage' => $usage,
            ];
        }
    }

    return ['category' => 'other', 'detail' => 'Classification failed', 'confidence' => 0, 'usage' => $usage];
}

/** Check if a document category is an invoice type that should be fully extracted */
function isInvoiceCategory($category) {
    return in_array($category, ['invoice', 'proforma', 'credit_note', 'order_confirmation']);
}
