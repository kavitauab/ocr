<?php
require_once __DIR__ . '/config.php';
$db = getDBConnection();

// Disable FK checks to allow column widening
$db->exec("SET FOREIGN_KEY_CHECKS = 0");

$alterStatements = [
    "ALTER TABLE companies MODIFY id VARCHAR(30) NOT NULL",
    "ALTER TABLE users MODIFY id VARCHAR(30) NOT NULL",
    "ALTER TABLE user_companies MODIFY id VARCHAR(30) NOT NULL",
    "ALTER TABLE user_companies MODIFY user_id VARCHAR(30) NOT NULL",
    "ALTER TABLE user_companies MODIFY company_id VARCHAR(30) NOT NULL",
    "ALTER TABLE invoices MODIFY id VARCHAR(30) NOT NULL",
    "ALTER TABLE invoices MODIFY company_id VARCHAR(30)",
    "ALTER TABLE invoices MODIFY email_inbox_id VARCHAR(30)",
    "ALTER TABLE line_items MODIFY id VARCHAR(30) NOT NULL",
    "ALTER TABLE line_items MODIFY invoice_id VARCHAR(30) NOT NULL",
    "ALTER TABLE email_inbox MODIFY id VARCHAR(30) NOT NULL",
    "ALTER TABLE email_inbox MODIFY company_id VARCHAR(30) NOT NULL",
    "ALTER TABLE usage_logs MODIFY id VARCHAR(30) NOT NULL",
    "ALTER TABLE usage_logs MODIFY company_id VARCHAR(30) NOT NULL",
    "ALTER TABLE audit_log MODIFY id VARCHAR(30) NOT NULL",
    "ALTER TABLE audit_log MODIFY user_id VARCHAR(30)",
    "ALTER TABLE audit_log MODIFY company_id VARCHAR(30)",
    "ALTER TABLE audit_log MODIFY resource_id VARCHAR(30)",
    "ALTER TABLE subscriptions MODIFY id VARCHAR(30) NOT NULL",
    "ALTER TABLE subscriptions MODIFY company_id VARCHAR(30) NOT NULL",
];

foreach ($alterStatements as $sql) {
    try {
        $db->exec($sql);
        echo "OK: $sql\n";
    } catch (Throwable $e) {
        echo "FAIL: $sql - " . $e->getMessage() . "\n";
    }
}

$db->exec("SET FOREIGN_KEY_CHECKS = 1");

// Clean up stuck processing invoices (from broken uploads with truncated IDs)
$deleted = $db->exec("DELETE FROM invoices WHERE status = 'processing' AND vendor_name IS NULL AND invoice_number IS NULL");
echo "\nCleaned up $deleted stuck processing invoices\n";

echo "\nDone!\n";
