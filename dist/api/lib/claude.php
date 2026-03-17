<?php

function extractInvoiceData($filePath, $fileType) {
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
8. For multi-page documents, examine ALL pages.";

    $tool = [
        'name' => 'save_invoice_data',
        'description' => 'Save the extracted invoice data. Call this tool with all extracted fields from the invoice document.',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'invoiceNumber' => ['type' => 'string', 'description' => 'The invoice number/ID as printed on the document'],
                'invoiceDate' => ['type' => 'string', 'description' => 'Invoice issue date in YYYY-MM-DD format'],
                'dueDate' => ['type' => ['string', 'null'], 'description' => 'Payment due date in YYYY-MM-DD format, or null if not stated'],
                'vendorName' => ['type' => 'string', 'description' => 'Name of the company/person issuing the invoice'],
                'vendorAddress' => ['type' => ['string', 'null'], 'description' => 'Full address of the vendor'],
                'vendorVatId' => ['type' => ['string', 'null'], 'description' => 'VAT/tax ID of the vendor'],
                'buyerName' => ['type' => ['string', 'null'], 'description' => 'Name of the buyer/recipient'],
                'buyerAddress' => ['type' => ['string', 'null'], 'description' => 'Full address of the buyer'],
                'buyerVatId' => ['type' => ['string', 'null'], 'description' => 'VAT/tax ID of the buyer'],
                'subtotalAmount' => ['type' => ['number', 'null'], 'description' => 'Subtotal before tax'],
                'taxAmount' => ['type' => ['number', 'null'], 'description' => 'Total tax/VAT amount'],
                'totalAmount' => ['type' => 'number', 'description' => 'Grand total amount including tax'],
                'currency' => ['type' => 'string', 'description' => 'Three-letter currency code (EUR, USD, GBP, etc.)'],
                'poNumber' => ['type' => ['string', 'null'], 'description' => 'Purchase order number, or null if not present'],
                'paymentTerms' => ['type' => ['string', 'null'], 'description' => 'Payment terms, or null if not stated'],
                'bankDetails' => ['type' => ['string', 'null'], 'description' => 'Bank/payment details including IBAN, account number, etc.'],
                'confidence' => [
                    'type' => 'object',
                    'description' => 'Confidence score (0.0 to 1.0) for each extracted field',
                    'properties' => [
                        'invoiceNumber' => ['type' => 'number'], 'invoiceDate' => ['type' => 'number'],
                        'dueDate' => ['type' => 'number'], 'vendorName' => ['type' => 'number'],
                        'vendorAddress' => ['type' => 'number'], 'vendorVatId' => ['type' => 'number'],
                        'buyerName' => ['type' => 'number'], 'buyerAddress' => ['type' => 'number'],
                        'buyerVatId' => ['type' => 'number'], 'totalAmount' => ['type' => 'number'],
                        'currency' => ['type' => 'number'], 'taxAmount' => ['type' => 'number'],
                        'subtotalAmount' => ['type' => 'number'], 'poNumber' => ['type' => 'number'],
                        'paymentTerms' => ['type' => 'number'], 'bankDetails' => ['type' => 'number'],
                    ],
                ],
            ],
            'required' => ['invoiceNumber', 'vendorName', 'totalAmount', 'currency', 'confidence'],
        ],
    ];

    $requestBody = [
        'model' => 'claude-sonnet-4-20250514',
        'max_tokens' => 4096,
        'system' => $systemPrompt,
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
            'anthropic-beta: pdfs-2024-09-25',
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

    // Find the tool_use block
    foreach ($data['content'] as $block) {
        if ($block['type'] === 'tool_use') {
            return $block['input'];
        }
    }

    throw new Exception('Claude did not return structured extraction data');
}
