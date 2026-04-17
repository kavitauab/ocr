<?php
// Cron endpoint - apply idempotent schema updates needed by current code.
// Auth: CRON_SECRET bearer token

verifyCronAuth();

$db = getDBConnection();

$summary = [
    'applied' => 0,
    'skipped' => 0,
    'errors' => 0,
    'statements' => [],
];

$record = function (string $status, string $statement, ?string $error = null) use (&$summary): void {
    if ($status === 'applied') {
        $summary['applied']++;
    } elseif ($status === 'skipped') {
        $summary['skipped']++;
    } else {
        $summary['errors']++;
    }

    $entry = [
        'status' => $status,
        'statement' => $statement,
    ];
    if ($error !== null) {
        $entry['error'] = $error;
    }
    $summary['statements'][] = $entry;
};

$tableExists = function (string $table) use ($db): bool {
    $stmt = $db->prepare(
        "SELECT 1
         FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = :table
         LIMIT 1"
    );
    $stmt->execute(['table' => $table]);
    return (bool)$stmt->fetchColumn();
};

$columnExists = function (string $table, string $column) use ($db): bool {
    $stmt = $db->prepare(
        "SELECT 1
         FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column
         LIMIT 1"
    );
    $stmt->execute(['table' => $table, 'column' => $column]);
    return (bool)$stmt->fetchColumn();
};

$indexExists = function (string $table, string $index) use ($db): bool {
    $stmt = $db->prepare(
        "SELECT 1
         FROM information_schema.statistics
         WHERE table_schema = DATABASE() AND table_name = :table AND index_name = :idx
         LIMIT 1"
    );
    $stmt->execute(['table' => $table, 'idx' => $index]);
    return (bool)$stmt->fetchColumn();
};

$runStatement = function (string $statement) use ($db, $record): void {
    try {
        $db->exec($statement);
        $record('applied', $statement);
    } catch (\Throwable $e) {
        $record('error', $statement, $e->getMessage());
    }
};

$ensureColumn = function (string $table, string $column, string $definition) use ($tableExists, $columnExists, $runStatement, $record): void {
    $statement = "ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}";

    if (!$tableExists($table)) {
        $record('error', $statement, "Table `{$table}` does not exist");
        return;
    }

    if ($columnExists($table, $column)) {
        $record('skipped', $statement);
        return;
    }

    $runStatement($statement);
};

$ensureIndex = function (string $table, string $index, string $definition) use ($tableExists, $indexExists, $runStatement, $record): void {
    $statement = "ALTER TABLE `{$table}` ADD INDEX `{$index}` {$definition}";

    if (!$tableExists($table)) {
        $record('error', $statement, "Table `{$table}` does not exist");
        return;
    }

    if ($indexExists($table, $index)) {
        $record('skipped', $statement);
        return;
    }

    $runStatement($statement);
};

$ensureUniqueIndex = function (string $table, string $index, string $definition) use ($tableExists, $indexExists, $runStatement, $record): void {
    $statement = "ALTER TABLE `{$table}` ADD UNIQUE INDEX `{$index}` {$definition}";

    if (!$tableExists($table)) {
        $record('error', $statement, "Table `{$table}` does not exist");
        return;
    }

    if ($indexExists($table, $index)) {
        $record('skipped', $statement);
        return;
    }

    $runStatement($statement);
};

$ensureTable = function (string $table, string $createStatement) use ($tableExists, $runStatement, $record): void {
    if ($tableExists($table)) {
        $record('skipped', $createStatement);
        return;
    }
    $runStatement($createStatement);
};

$ensureColumn('invoices', 'ocr_sent_at', 'DATETIME NULL');
$ensureColumn('invoices', 'ocr_returned_at', 'DATETIME NULL');
$ensureColumn('invoices', 'document_type', 'VARCHAR(50) DEFAULT NULL');
$ensureIndex('invoices', 'idx_ocr_sent_at', '(`ocr_sent_at`)');
$ensureIndex('invoices', 'idx_ocr_returned_at', '(`ocr_returned_at`)');

$ensureColumn('usage_logs', 'ocr_jobs_count', 'INT NOT NULL DEFAULT 0');
$ensureColumn('usage_logs', 'ocr_input_tokens', 'BIGINT NOT NULL DEFAULT 0');
$ensureColumn('usage_logs', 'ocr_output_tokens', 'BIGINT NOT NULL DEFAULT 0');
$ensureColumn('usage_logs', 'ocr_total_tokens', 'BIGINT NOT NULL DEFAULT 0');
$ensureColumn('usage_logs', 'ocr_cost_usd', 'DECIMAL(14,6) NOT NULL DEFAULT 0');

$ensureTable(
    'ocr_jobs',
    "CREATE TABLE `ocr_jobs` (
      `id` VARCHAR(30) NOT NULL PRIMARY KEY,
      `invoice_id` VARCHAR(30) NOT NULL,
      `company_id` VARCHAR(30) NOT NULL,
      `provider` VARCHAR(50) NOT NULL DEFAULT 'anthropic',
      `model` VARCHAR(100),
      `status` ENUM('processing','completed','failed') NOT NULL DEFAULT 'processing',
      `request_id` VARCHAR(255),
      `input_tokens` INT NOT NULL DEFAULT 0,
      `output_tokens` INT NOT NULL DEFAULT 0,
      `total_tokens` INT NOT NULL DEFAULT 0,
      `cache_creation_input_tokens` INT NOT NULL DEFAULT 0,
      `cache_read_input_tokens` INT NOT NULL DEFAULT 0,
      `cost_usd` DECIMAL(14,6) NOT NULL DEFAULT 0,
      `error_message` TEXT,
      `sent_at` DATETIME NULL,
      `returned_at` DATETIME NULL,
      `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX `idx_ocr_jobs_company_created` (`company_id`, `created_at`),
      INDEX `idx_ocr_jobs_invoice` (`invoice_id`),
      INDEX `idx_ocr_jobs_status` (`status`),
      FOREIGN KEY (`invoice_id`) REFERENCES `invoices`(`id`) ON DELETE CASCADE,
      FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$ensureColumn('subscriptions', 'included_tokens', 'BIGINT');
$ensureColumn('subscriptions', 'overage_per_1k_tokens_usd', 'DECIMAL(12,6)');
$ensureColumn('subscriptions', 'overage_per_invoice_usd', 'DECIMAL(12,6)');

// --- Extend invoices status to include 'queued' and 'retrying' ---
$invoiceStatusStatement = "ALTER TABLE `invoices` MODIFY COLUMN `status` ENUM('uploaded','queued','processing','completed','failed','retrying') NOT NULL DEFAULT 'uploaded'";
if ($tableExists('invoices') && $columnExists('invoices', 'status')) {
    $stmt = $db->prepare("SELECT COLUMN_TYPE FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'invoices' AND column_name = 'status'");
    $stmt->execute();
    $colType = $stmt->fetchColumn();
    if ($colType && strpos($colType, 'queued') === false) {
        $runStatement($invoiceStatusStatement);
    } else {
        $record('skipped', $invoiceStatusStatement);
    }
} else {
    $record('skipped', $invoiceStatusStatement);
}

// --- OCR Queue & Retry columns on ocr_jobs ---
$ensureColumn('ocr_jobs', 'attempt', 'INT NOT NULL DEFAULT 1');
$ensureColumn('ocr_jobs', 'max_attempts', 'INT NOT NULL DEFAULT 3');
$ensureColumn('ocr_jobs', 'next_retry_at', 'DATETIME NULL');
$ensureColumn('ocr_jobs', 'queued_at', 'DATETIME NULL');

// Extend ocr_jobs status enum to include 'queued' and 'retrying'
// MySQL ALTER COLUMN MODIFY is idempotent if run multiple times
$ocrJobsStatusStatement = "ALTER TABLE `ocr_jobs` MODIFY COLUMN `status` ENUM('queued','processing','completed','failed','retrying') NOT NULL DEFAULT 'queued'";
if ($tableExists('ocr_jobs') && $columnExists('ocr_jobs', 'status')) {
    // Check current enum values
    $stmt = $db->prepare("SELECT COLUMN_TYPE FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ocr_jobs' AND column_name = 'status'");
    $stmt->execute();
    $colType = $stmt->fetchColumn();
    if ($colType && strpos($colType, 'queued') === false) {
        $runStatement($ocrJobsStatusStatement);
    } else {
        $record('skipped', $ocrJobsStatusStatement);
    }
} else {
    $record('skipped', $ocrJobsStatusStatement);
}

$ensureIndex('ocr_jobs', 'idx_ocr_jobs_queue', '(`status`, `next_retry_at`)');

// --- Rate limiting columns on subscriptions ---
$ensureColumn('subscriptions', 'rate_limit_per_hour', 'INT NULL');
$ensureColumn('subscriptions', 'rate_limit_per_day', 'INT NULL');

// --- Vecticum external ID on invoices ---
$ensureColumn('invoices', 'vecticum_id', 'VARCHAR(255) NULL');

// --- Vecticum partner endpoint on companies ---
$ensureColumn('companies', 'vecticum_partner_endpoint', 'VARCHAR(255) NULL');

// --- Vecticum sent timestamp on invoices ---
$ensureColumn('invoices', 'vecticum_sent_at', 'DATETIME NULL');

// --- Vecticum invoice class ID (separate from company ID) ---
$ensureColumn('companies', 'vecticum_class_id', 'VARCHAR(255) NULL');

// --- Vecticum inbox setup ID for mailbox-specific default author resolution ---
$ensureColumn('companies', 'vecticum_inbox_setup_id', 'VARCHAR(255) NULL');

// --- Vecticum error tracking on invoices ---
$ensureColumn('invoices', 'vecticum_error', 'TEXT NULL');

// --- Automatic issue reply tracking on invoices ---
$ensureColumn('invoices', 'issue_reply_sent_at', 'DATETIME NULL');
$ensureColumn('invoices', 'issue_reply_reason', 'VARCHAR(100) NULL');
$ensureColumn('invoices', 'issue_reply_error', 'TEXT NULL');

// --- Vecticum auto-send on companies ---
$ensureColumn('companies', 'vecticum_auto_send', 'TINYINT(1) NOT NULL DEFAULT 0');

// --- Company VAT number and buyer keywords for buyer validation ---
$ensureColumn('companies', 'vat_number', 'VARCHAR(100) NULL');
$ensureColumn('companies', 'buyer_keywords', 'VARCHAR(500) NULL');

// --- OCR model tracking on invoices ---
$ensureColumn('invoices', 'ocr_model', 'VARCHAR(100) NULL');
$ensureColumn('invoices', 'ocr_escalated', 'TINYINT(1) NOT NULL DEFAULT 0');
$ensureColumn('invoices', 'ocr_escalation_reason', 'VARCHAR(500) NULL');

// --- Document classification: skip_reason + additional_files + skipped status ---
$ensureColumn('invoices', 'skip_reason', 'VARCHAR(255) NULL');
$ensureColumn('invoices', 'additional_files', 'JSON NULL');

// --- Performance indexes for hot query paths ---
// List + stats + queue scans filter on status; most screens sort by created_at
$ensureIndex('invoices', 'idx_invoices_status_created', '(`status`, `created_at`)');
// Rate limits, per-company listings, dashboard company filter
$ensureIndex('invoices', 'idx_invoices_company_created', '(`company_id`, `created_at`)');
// Email inbox company filter + ordering
$ensureIndex('email_inbox', 'idx_email_inbox_company_created', '(`company_id`, `received_date`)');
// Cron picker orders queued/retrying by queued_at
$ensureIndex('ocr_jobs', 'idx_ocr_jobs_queue_ordered', '(`status`, `next_retry_at`, `queued_at`)');

// --- Prevent duplicate email insertion on concurrent cron runs ---
// email_inbox.message_id must be globally unique to let us use INSERT IGNORE.
$ensureUniqueIndex('email_inbox', 'uniq_email_inbox_message_id', '(`message_id`)');

// --- Force case-sensitive collation on message_id ---
// Microsoft Graph IDs are base64-like and CASE-SENSITIVE. Our default
// utf8mb4_unicode_ci collation treated case-variants as duplicates, so
// INSERT IGNORE silently dropped new emails whose Graph IDs case-collided
// with earlier ones. Switching to utf8mb4_bin makes the unique constraint
// treat them correctly.
if ($tableExists('email_inbox') && $columnExists('email_inbox', 'message_id')) {
    $stmt = $db->prepare("SELECT COLLATION_NAME FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'email_inbox' AND column_name = 'message_id'");
    $stmt->execute();
    $coll = $stmt->fetchColumn();
    $msgIdCollationFix = "ALTER TABLE `email_inbox` MODIFY COLUMN `message_id` VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL";
    if ($coll && $coll !== 'utf8mb4_bin') {
        $runStatement($msgIdCollationFix);
    } else {
        $record('skipped', $msgIdCollationFix);
    }
} else {
    $record('skipped', 'email_inbox.message_id collation change');
}

$invoiceStatusSkipped = "ALTER TABLE `invoices` MODIFY COLUMN `status` ENUM('uploaded','queued','processing','completed','failed','retrying','skipped') NOT NULL DEFAULT 'uploaded'";
if ($tableExists('invoices') && $columnExists('invoices', 'status')) {
    $stmt = $db->prepare("SELECT COLUMN_TYPE FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'invoices' AND column_name = 'status'");
    $stmt->execute();
    $colType = $stmt->fetchColumn();
    if ($colType && strpos($colType, 'skipped') === false) {
        $runStatement($invoiceStatusSkipped);
    } else {
        $record('skipped', $invoiceStatusSkipped);
    }
} else {
    $record('skipped', $invoiceStatusSkipped);
}

$statusCode = $summary['errors'] > 0 ? 500 : 200;
sendJSON($summary, $statusCode);
