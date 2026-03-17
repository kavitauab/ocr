<?php
require_once __DIR__ . '/config.php';
$db = getDBConnection();

// Widen all VARCHAR(21) ID columns to VARCHAR(30)
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

// Also delete any orphaned invoices stuck in 'processing' from previous broken uploads
$stmt = $db->query("SELECT id, status, vendor_name FROM invoices WHERE status = 'processing'");
$stuck = $stmt->fetchAll();
echo "\nStuck processing invoices: " . count($stuck) . "\n";
foreach ($stuck as $inv) {
    echo "  - {$inv['id']} (vendor: {$inv['vendor_name']})\n";
}

echo "\nDone!\n";
