<?php
/**
 * CLI cron runner - call from system cron without HTTP.
 *
 * Usage:
 *   php cron.php process-ocr
 *   php cron.php fetch-emails
 *   php cron.php migrate-schema
 */

if (php_sapi_name() !== 'cli') {
    exit(1);
}

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/api/cron';

require_once __DIR__ . '/config.php';

$task = $argv[1] ?? 'all';

// Set auth header so included scripts pass their CRON_SECRET check
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . CRON_SECRET;

if ($task === 'all') {
    // Run fetch-emails then process-ocr via curl to own API
    $base = 'http://localhost';
    $secret = CRON_SECRET;

    $ch = curl_init("$base/api/cron/fetch-emails");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ["Authorization: Bearer $secret"], CURLOPT_TIMEOUT => 120]);
    $r1 = curl_exec($ch); curl_close($ch);
    echo "fetch-emails: $r1\n";

    $ch = curl_init("$base/api/cron/process-ocr");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ["Authorization: Bearer $secret"], CURLOPT_TIMEOUT => 120]);
    $r2 = curl_exec($ch); curl_close($ch);
    echo "process-ocr: $r2\n";

    exit(0);
}

switch ($task) {
    case 'process-ocr':
        require_once __DIR__ . '/functions/process_ocr_queue.php';
        break;
    case 'fetch-emails':
        require_once __DIR__ . '/functions/fetch_emails.php';
        break;
    case 'migrate-schema':
        require_once __DIR__ . '/functions/migrate_schema.php';
        break;
    default:
        fwrite(STDERR, "Unknown task: $task\n");
        exit(1);
}
