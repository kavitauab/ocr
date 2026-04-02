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

$task = $argv[1] ?? '';

if (!$task) {
    fwrite(STDERR, "Usage: php cron.php <task>\nTasks: process-ocr, fetch-emails, migrate-schema\n");
    exit(1);
}

// Set auth header so included scripts pass their CRON_SECRET check
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . CRON_SECRET;

switch ($task) {
    case 'all':
        // Run both fetch-emails and process-ocr sequentially via subprocess
        $phpBin = PHP_BINARY ?: 'php';
        $dir = __DIR__;
        passthru("$phpBin $dir/cron.php fetch-emails 2>&1");
        passthru("$phpBin $dir/cron.php process-ocr 2>&1");
        exit(0);
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
