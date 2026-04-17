<?php
// Cron endpoint - prune old OCR job rows to keep the queue picker fast.
// Auth: CRON_SECRET bearer token (timing-safe)
// Call: GET /api/cron/cleanup  (optionally ?days=N, default 90)
//
// Safe to run frequently. Only touches completed/failed ocr_jobs — leaves
// invoices, email_inbox, and usage_logs alone.

verifyCronAuth();

$days = max(7, min(365, intval($_GET['days'] ?? 90)));

$db = getDBConnection();

$result = [
    'action' => 'cleanup',
    'cutoffDays' => $days,
    'deleted' => [],
];

try {
    // Prune finished OCR jobs older than the cutoff.
    $stmt = $db->prepare(
        "DELETE FROM ocr_jobs
         WHERE status IN ('completed','failed')
         AND returned_at IS NOT NULL
         AND returned_at < DATE_SUB(NOW(), INTERVAL :days DAY)"
    );
    $stmt->bindValue(':days', $days, PDO::PARAM_INT);
    $stmt->execute();
    $result['deleted']['ocr_jobs'] = $stmt->rowCount();
} catch (\Throwable $e) {
    $result['errors'][] = 'ocr_jobs: ' . $e->getMessage();
}

try {
    // Clear stale in-memory cron version file marker. Not strictly necessary
    // but prevents confusion from a file dated months ago.
    $cronVerFile = __DIR__ . '/../_cron_version.txt';
    if (file_exists($cronVerFile)) {
        @file_put_contents($cronVerFile, API_VERSION . ' | ' . date('Y-m-d H:i:s') . ' | cleanup');
    }
} catch (\Throwable $e) {
    // Non-critical
}

sendJSON($result);
