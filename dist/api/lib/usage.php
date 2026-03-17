<?php

function trackInvoiceProcessed($companyId, $fileSize) {
    $db = getDBConnection();
    $month = date('Y-m');

    $stmt = $db->prepare("UPDATE usage_logs SET invoices_processed = invoices_processed + 1, storage_used_bytes = storage_used_bytes + :size, api_calls_count = api_calls_count + 1, updated_at = NOW() WHERE company_id = :companyId AND month = :month");
    $stmt->execute(['size' => $fileSize, 'companyId' => $companyId, 'month' => $month]);

    if ($stmt->rowCount() === 0) {
        $stmt = $db->prepare("INSERT INTO usage_logs (id, company_id, month, invoices_processed, storage_used_bytes, api_calls_count) VALUES (:id, :companyId, :month, 1, :size, 1)");
        $stmt->execute(['id' => generateId(), 'companyId' => $companyId, 'month' => $month, 'size' => $fileSize]);
    }
}

function trackApiCall($companyId) {
    $db = getDBConnection();
    $month = date('Y-m');

    $stmt = $db->prepare("UPDATE usage_logs SET api_calls_count = api_calls_count + 1, updated_at = NOW() WHERE company_id = :companyId AND month = :month");
    $stmt->execute(['companyId' => $companyId, 'month' => $month]);

    if ($stmt->rowCount() === 0) {
        $stmt = $db->prepare("INSERT INTO usage_logs (id, company_id, month, invoices_processed, storage_used_bytes, api_calls_count) VALUES (:id, :companyId, :month, 0, 0, 1)");
        $stmt->execute(['id' => generateId(), 'companyId' => $companyId, 'month' => $month]);
    }
}
