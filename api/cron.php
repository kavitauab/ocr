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
    // Run both tasks in sequence via subprocesses
    $php = PHP_BINARY;
    if (!$php || !file_exists($php)) {
        $php = trim(shell_exec('which php 2>/dev/null') ?: '/usr/bin/php');
    }
    $dir = __DIR__;
    // Log which PHP binary and directory we're using
    error_log("[CRON ALL] php=$php dir=$dir version=" . (defined('API_VERSION') ? API_VERSION : 'UNDEF'));
    echo "=== fetch-emails ===\n";
    passthru("$php " . escapeshellarg("$dir/cron.php") . " fetch-emails 2>&1");
    echo "\n=== process-ocr ===\n";
    passthru("$php " . escapeshellarg("$dir/cron.php") . " process-ocr 2>&1");
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
