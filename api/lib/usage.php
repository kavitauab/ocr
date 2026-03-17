<?php

function getOcrModelPricingUsdPerMillion($model) {
    $pricing = [
        'claude-sonnet-4-20250514' => [
            'input' => 3.00,
            'output' => 15.00,
            'cache_creation_input' => 3.75,
            'cache_read_input' => 0.30,
        ],
    ];

    return $pricing[$model] ?? null;
}

function calculateEstimatedOcrCostUsd($model, $inputTokens, $outputTokens, $cacheCreationInputTokens, $cacheReadInputTokens) {
    $rates = getOcrModelPricingUsdPerMillion($model);
    if (!$rates) {
        return 0.0;
    }

    $million = 1000000.0;
    $cost = 0.0;
    $cost += ($inputTokens / $million) * $rates['input'];
    $cost += ($outputTokens / $million) * $rates['output'];
    $cost += ($cacheCreationInputTokens / $million) * $rates['cache_creation_input'];
    $cost += ($cacheReadInputTokens / $million) * $rates['cache_read_input'];

    return round($cost, 6);
}

function normalizeOcrUsage($usage = null) {
    if (!is_array($usage)) {
        $usage = [];
    }

    $inputTokens = (int)($usage['inputTokens'] ?? $usage['input_tokens'] ?? 0);
    $outputTokens = (int)($usage['outputTokens'] ?? $usage['output_tokens'] ?? 0);
    $cacheCreationInputTokens = (int)($usage['cacheCreationInputTokens'] ?? $usage['cache_creation_input_tokens'] ?? 0);
    $cacheReadInputTokens = (int)($usage['cacheReadInputTokens'] ?? $usage['cache_read_input_tokens'] ?? 0);

    $explicitTotal = (int)($usage['totalTokens'] ?? $usage['total_tokens'] ?? 0);
    $computedTotal = $inputTokens + $outputTokens + $cacheCreationInputTokens + $cacheReadInputTokens;
    $totalTokens = $explicitTotal > 0 ? $explicitTotal : $computedTotal;

    $model = $usage['model'] ?? null;
    $provider = $usage['provider'] ?? 'anthropic';
    $requestId = $usage['requestId'] ?? $usage['request_id'] ?? null;

    $costUsd = isset($usage['costUsd']) || isset($usage['cost_usd'])
        ? (float)($usage['costUsd'] ?? $usage['cost_usd'])
        : calculateEstimatedOcrCostUsd($model, $inputTokens, $outputTokens, $cacheCreationInputTokens, $cacheReadInputTokens);

    return [
        'provider' => $provider,
        'model' => $model,
        'requestId' => $requestId,
        'inputTokens' => max(0, $inputTokens),
        'outputTokens' => max(0, $outputTokens),
        'cacheCreationInputTokens' => max(0, $cacheCreationInputTokens),
        'cacheReadInputTokens' => max(0, $cacheReadInputTokens),
        'totalTokens' => max(0, $totalTokens),
        'costUsd' => max(0.0, round($costUsd, 6)),
    ];
}

function trackInvoiceProcessed($companyId, $fileSize, $usage = null) {
    $db = getDBConnection();
    $month = date('Y-m');
    $metrics = normalizeOcrUsage($usage);
    $costUsd = number_format($metrics['costUsd'], 6, '.', '');

    try {
        $stmt = $db->prepare("UPDATE usage_logs SET
            invoices_processed = invoices_processed + 1,
            storage_used_bytes = storage_used_bytes + :size,
            api_calls_count = api_calls_count + 1,
            ocr_jobs_count = ocr_jobs_count + 1,
            ocr_input_tokens = ocr_input_tokens + :inputTokens,
            ocr_output_tokens = ocr_output_tokens + :outputTokens,
            ocr_total_tokens = ocr_total_tokens + :totalTokens,
            ocr_cost_usd = ocr_cost_usd + :costUsd,
            updated_at = NOW()
            WHERE company_id = :companyId AND month = :month");
        $stmt->execute([
            'size' => $fileSize,
            'inputTokens' => $metrics['inputTokens'],
            'outputTokens' => $metrics['outputTokens'],
            'totalTokens' => $metrics['totalTokens'],
            'costUsd' => $costUsd,
            'companyId' => $companyId,
            'month' => $month,
        ]);

        if ($stmt->rowCount() === 0) {
            $stmt = $db->prepare("INSERT INTO usage_logs (
                id, company_id, month, invoices_processed, storage_used_bytes, api_calls_count,
                ocr_jobs_count, ocr_input_tokens, ocr_output_tokens, ocr_total_tokens, ocr_cost_usd
            ) VALUES (
                :id, :companyId, :month, 1, :size, 1,
                1, :inputTokens, :outputTokens, :totalTokens, :costUsd
            )");
            $stmt->execute([
                'id' => generateId(),
                'companyId' => $companyId,
                'month' => $month,
                'size' => $fileSize,
                'inputTokens' => $metrics['inputTokens'],
                'outputTokens' => $metrics['outputTokens'],
                'totalTokens' => $metrics['totalTokens'],
                'costUsd' => $costUsd,
            ]);
        }
    } catch (\Throwable $e) {
        // Backward-compatible fallback for DBs that have not yet added new usage columns.
        $stmt = $db->prepare("UPDATE usage_logs
            SET invoices_processed = invoices_processed + 1,
                storage_used_bytes = storage_used_bytes + :size,
                api_calls_count = api_calls_count + 1,
                updated_at = NOW()
            WHERE company_id = :companyId AND month = :month");
        $stmt->execute(['size' => $fileSize, 'companyId' => $companyId, 'month' => $month]);

        if ($stmt->rowCount() === 0) {
            $stmt = $db->prepare("INSERT INTO usage_logs (id, company_id, month, invoices_processed, storage_used_bytes, api_calls_count)
                VALUES (:id, :companyId, :month, 1, :size, 1)");
            $stmt->execute(['id' => generateId(), 'companyId' => $companyId, 'month' => $month, 'size' => $fileSize]);
        }
    }
}

function trackApiCall($companyId, $usage = null) {
    $db = getDBConnection();
    $month = date('Y-m');
    $metrics = normalizeOcrUsage($usage);
    $hasOcrUsage = is_array($usage);
    $jobIncrement = $hasOcrUsage ? 1 : 0;
    $costUsd = number_format($metrics['costUsd'], 6, '.', '');

    try {
        $stmt = $db->prepare("UPDATE usage_logs SET
            api_calls_count = api_calls_count + 1,
            ocr_jobs_count = ocr_jobs_count + :jobIncrement,
            ocr_input_tokens = ocr_input_tokens + :inputTokens,
            ocr_output_tokens = ocr_output_tokens + :outputTokens,
            ocr_total_tokens = ocr_total_tokens + :totalTokens,
            ocr_cost_usd = ocr_cost_usd + :costUsd,
            updated_at = NOW()
            WHERE company_id = :companyId AND month = :month");
        $stmt->execute([
            'jobIncrement' => $jobIncrement,
            'inputTokens' => $metrics['inputTokens'],
            'outputTokens' => $metrics['outputTokens'],
            'totalTokens' => $metrics['totalTokens'],
            'costUsd' => $costUsd,
            'companyId' => $companyId,
            'month' => $month,
        ]);

        if ($stmt->rowCount() === 0) {
            $stmt = $db->prepare("INSERT INTO usage_logs (
                id, company_id, month, invoices_processed, storage_used_bytes, api_calls_count,
                ocr_jobs_count, ocr_input_tokens, ocr_output_tokens, ocr_total_tokens, ocr_cost_usd
            ) VALUES (
                :id, :companyId, :month, 0, 0, 1,
                :jobIncrement, :inputTokens, :outputTokens, :totalTokens, :costUsd
            )");
            $stmt->execute([
                'id' => generateId(),
                'companyId' => $companyId,
                'month' => $month,
                'jobIncrement' => $jobIncrement,
                'inputTokens' => $metrics['inputTokens'],
                'outputTokens' => $metrics['outputTokens'],
                'totalTokens' => $metrics['totalTokens'],
                'costUsd' => $costUsd,
            ]);
        }
    } catch (\Throwable $e) {
        // Backward-compatible fallback for DBs that have not yet added new usage columns.
        $stmt = $db->prepare("UPDATE usage_logs
            SET api_calls_count = api_calls_count + 1, updated_at = NOW()
            WHERE company_id = :companyId AND month = :month");
        $stmt->execute(['companyId' => $companyId, 'month' => $month]);

        if ($stmt->rowCount() === 0) {
            $stmt = $db->prepare("INSERT INTO usage_logs (id, company_id, month, invoices_processed, storage_used_bytes, api_calls_count)
                VALUES (:id, :companyId, :month, 0, 0, 1)");
            $stmt->execute(['id' => generateId(), 'companyId' => $companyId, 'month' => $month]);
        }
    }
}

function startOcrJob($invoiceId, $companyId, $provider = 'anthropic', $model = null) {
    $db = getDBConnection();
    $jobId = generateId();

    try {
        $stmt = $db->prepare("INSERT INTO ocr_jobs (
            id, invoice_id, company_id, provider, model, status, sent_at
        ) VALUES (
            :id, :invoiceId, :companyId, :provider, :model, 'processing', NOW()
        )");
        $stmt->execute([
            'id' => $jobId,
            'invoiceId' => $invoiceId,
            'companyId' => $companyId,
            'provider' => $provider,
            'model' => $model,
        ]);
        return $jobId;
    } catch (\Throwable $e) {
        // Keep OCR flow running even if the ocr_jobs table is not available yet.
        error_log('startOcrJob failed: ' . $e->getMessage());
        return null;
    }
}

function completeOcrJob($jobId, $usage = null) {
    if (!$jobId) return;

    $db = getDBConnection();
    $metrics = normalizeOcrUsage($usage);

    try {
        $stmt = $db->prepare("UPDATE ocr_jobs SET
            status = 'completed',
            request_id = :requestId,
            input_tokens = :inputTokens,
            output_tokens = :outputTokens,
            total_tokens = :totalTokens,
            cache_creation_input_tokens = :cacheCreationInputTokens,
            cache_read_input_tokens = :cacheReadInputTokens,
            cost_usd = :costUsd,
            provider = COALESCE(:provider, provider),
            model = COALESCE(:model, model),
            returned_at = NOW(),
            updated_at = NOW()
            WHERE id = :id");
        $stmt->execute([
            'requestId' => $metrics['requestId'],
            'inputTokens' => $metrics['inputTokens'],
            'outputTokens' => $metrics['outputTokens'],
            'totalTokens' => $metrics['totalTokens'],
            'cacheCreationInputTokens' => $metrics['cacheCreationInputTokens'],
            'cacheReadInputTokens' => $metrics['cacheReadInputTokens'],
            'costUsd' => number_format($metrics['costUsd'], 6, '.', ''),
            'provider' => $metrics['provider'],
            'model' => $metrics['model'],
            'id' => $jobId,
        ]);
    } catch (\Throwable $e) {
        error_log('completeOcrJob failed: ' . $e->getMessage());
    }
}

function failOcrJob($jobId, $errorMessage, $usage = null) {
    if (!$jobId) return;

    $db = getDBConnection();
    $metrics = normalizeOcrUsage($usage);

    try {
        $stmt = $db->prepare("UPDATE ocr_jobs SET
            status = 'failed',
            error_message = :errorMessage,
            request_id = :requestId,
            input_tokens = :inputTokens,
            output_tokens = :outputTokens,
            total_tokens = :totalTokens,
            cache_creation_input_tokens = :cacheCreationInputTokens,
            cache_read_input_tokens = :cacheReadInputTokens,
            cost_usd = :costUsd,
            provider = COALESCE(:provider, provider),
            model = COALESCE(:model, model),
            returned_at = NOW(),
            updated_at = NOW()
            WHERE id = :id");
        $stmt->execute([
            'errorMessage' => $errorMessage,
            'requestId' => $metrics['requestId'],
            'inputTokens' => $metrics['inputTokens'],
            'outputTokens' => $metrics['outputTokens'],
            'totalTokens' => $metrics['totalTokens'],
            'cacheCreationInputTokens' => $metrics['cacheCreationInputTokens'],
            'cacheReadInputTokens' => $metrics['cacheReadInputTokens'],
            'costUsd' => number_format($metrics['costUsd'], 6, '.', ''),
            'provider' => $metrics['provider'],
            'model' => $metrics['model'],
            'id' => $jobId,
        ]);
    } catch (\Throwable $e) {
        error_log('failOcrJob failed: ' . $e->getMessage());
    }
}
