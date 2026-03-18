<?php

class Subscriptions extends BaseResource {
    private $subscriptionColumns = null;
    private $subscriptionsTableAvailable = null;

    private function loadSubscriptionColumns() {
        if ($this->subscriptionColumns !== null) {
            return;
        }

        try {
            $rows = $this->db->query("SHOW COLUMNS FROM subscriptions")->fetchAll();
            $this->subscriptionColumns = array_map(fn($row) => $row['Field'], $rows);
            $this->subscriptionsTableAvailable = true;
        } catch (\Throwable $e) {
            $this->subscriptionColumns = [];
            $this->subscriptionsTableAvailable = false;
        }
    }

    private function hasSubscriptionColumn($column) {
        $this->loadSubscriptionColumns();
        return in_array($column, $this->subscriptionColumns, true);
    }

    private function isSubscriptionsTableAvailable() {
        $this->loadSubscriptionColumns();
        return $this->subscriptionsTableAvailable === true;
    }

    private function getDefaultSubscriptionValues() {
        return [
            'plan' => 'free',
            'status' => 'active',
            'invoiceLimit' => null,
            'storageLimitBytes' => null,
            'includedTokens' => null,
            'overagePer1kTokensUsd' => null,
            'overagePerInvoiceUsd' => null,
            'rateLimitPerHour' => null,
            'rateLimitPerDay' => null,
        ];
    }

    private function selectFragment($column, $alias, $fallbackSql = 'NULL') {
        if ($this->hasSubscriptionColumn($column)) {
            return "s.$column AS $alias";
        }
        return "$fallbackSql AS $alias";
    }

    private function normalizeNullableInt($value, $fieldName) {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', trim($value))) {
            return (int)$value;
        }
        if (is_float($value) && floor($value) === $value) {
            return (int)$value;
        }

        sendJSON(['error' => "Invalid $fieldName; expected integer or null"], 400);
    }

    private function normalizeNullableFloat($value, $fieldName) {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return (float)$value;
        }
        if (is_string($value) && is_numeric(trim($value))) {
            return (float)$value;
        }

        sendJSON(['error' => "Invalid $fieldName; expected number or null"], 400);
    }

    private function normalizeUpdatePayload($data) {
        $result = [];

        if (array_key_exists('plan', $data)) {
            if (!is_string($data['plan']) || trim($data['plan']) === '') {
                sendJSON(['error' => 'Invalid plan; expected non-empty string'], 400);
            }
            $result['plan'] = trim($data['plan']);
        }
        if (array_key_exists('status', $data)) {
            if (!is_string($data['status']) || trim($data['status']) === '') {
                sendJSON(['error' => 'Invalid status; expected non-empty string'], 400);
            }
            $result['status'] = trim($data['status']);
        }

        if (array_key_exists('invoiceLimit', $data) || array_key_exists('invoice_limit', $data)) {
            $raw = array_key_exists('invoiceLimit', $data) ? $data['invoiceLimit'] : $data['invoice_limit'];
            $result['invoice_limit'] = $this->normalizeNullableInt($raw, 'invoiceLimit');
        }

        if (array_key_exists('storageLimitBytes', $data) || array_key_exists('storage_limit_bytes', $data)) {
            $raw = array_key_exists('storageLimitBytes', $data) ? $data['storageLimitBytes'] : $data['storage_limit_bytes'];
            $result['storage_limit_bytes'] = $this->normalizeNullableInt($raw, 'storageLimitBytes');
        }

        if (array_key_exists('includedTokens', $data) || array_key_exists('included_tokens', $data)) {
            $raw = array_key_exists('includedTokens', $data) ? $data['includedTokens'] : $data['included_tokens'];
            $result['included_tokens'] = $this->normalizeNullableInt($raw, 'includedTokens');
        }

        if (array_key_exists('overagePer1kTokensUsd', $data) || array_key_exists('overage_per_1k_tokens_usd', $data)) {
            $raw = array_key_exists('overagePer1kTokensUsd', $data) ? $data['overagePer1kTokensUsd'] : $data['overage_per_1k_tokens_usd'];
            $result['overage_per_1k_tokens_usd'] = $this->normalizeNullableFloat($raw, 'overagePer1kTokensUsd');
        }

        if (array_key_exists('overagePerInvoiceUsd', $data) || array_key_exists('overage_per_invoice_usd', $data)) {
            $raw = array_key_exists('overagePerInvoiceUsd', $data) ? $data['overagePerInvoiceUsd'] : $data['overage_per_invoice_usd'];
            $result['overage_per_invoice_usd'] = $this->normalizeNullableFloat($raw, 'overagePerInvoiceUsd');
        }

        if (array_key_exists('rateLimitPerHour', $data) || array_key_exists('rate_limit_per_hour', $data)) {
            $raw = array_key_exists('rateLimitPerHour', $data) ? $data['rateLimitPerHour'] : $data['rate_limit_per_hour'];
            $result['rate_limit_per_hour'] = $this->normalizeNullableInt($raw, 'rateLimitPerHour');
        }

        if (array_key_exists('rateLimitPerDay', $data) || array_key_exists('rate_limit_per_day', $data)) {
            $raw = array_key_exists('rateLimitPerDay', $data) ? $data['rateLimitPerDay'] : $data['rate_limit_per_day'];
            $result['rate_limit_per_day'] = $this->normalizeNullableInt($raw, 'rateLimitPerDay');
        }

        return $result;
    }

    private function toIntOrNull($value) {
        if ($value === null) return null;
        return (int)$value;
    }

    private function toFloatOrNull($value) {
        if ($value === null) return null;
        return (float)$value;
    }

    private function formatSubscriptionRow($row) {
        $defaults = $this->getDefaultSubscriptionValues();

        return [
            'companyId' => $row['company_id'],
            'companyName' => $row['company_name'],
            'companyCode' => $row['company_code'],
            'plan' => $row['plan'] ?? $defaults['plan'],
            'status' => $row['status'] ?? $defaults['status'],
            'invoiceLimit' => $this->toIntOrNull($row['invoice_limit'] ?? $defaults['invoiceLimit']),
            'storageLimitBytes' => $this->toIntOrNull($row['storage_limit_bytes'] ?? $defaults['storageLimitBytes']),
            'includedTokens' => $this->toIntOrNull($row['included_tokens'] ?? $defaults['includedTokens']),
            'overagePer1kTokensUsd' => $this->toFloatOrNull($row['overage_per_1k_tokens_usd'] ?? $defaults['overagePer1kTokensUsd']),
            'overagePerInvoiceUsd' => $this->toFloatOrNull($row['overage_per_invoice_usd'] ?? $defaults['overagePerInvoiceUsd']),
            'rateLimitPerHour' => $this->toIntOrNull($row['rate_limit_per_hour'] ?? $defaults['rateLimitPerHour']),
            'rateLimitPerDay' => $this->toIntOrNull($row['rate_limit_per_day'] ?? $defaults['rateLimitPerDay']),
        ];
    }

    private function getListSql() {
        if ($this->isSubscriptionsTableAvailable()) {
            $selectParts = [
                'c.id AS company_id',
                'c.name AS company_name',
                'c.code AS company_code',
                $this->selectFragment('plan', 'plan', "'free'"),
                $this->selectFragment('status', 'status', "'active'"),
                $this->selectFragment('invoice_limit', 'invoice_limit'),
                $this->selectFragment('storage_limit_bytes', 'storage_limit_bytes'),
                $this->selectFragment('included_tokens', 'included_tokens'),
                $this->selectFragment('overage_per_1k_tokens_usd', 'overage_per_1k_tokens_usd'),
                $this->selectFragment('overage_per_invoice_usd', 'overage_per_invoice_usd'),
                $this->selectFragment('rate_limit_per_hour', 'rate_limit_per_hour'),
                $this->selectFragment('rate_limit_per_day', 'rate_limit_per_day'),
            ];

            return "SELECT " . implode(', ', $selectParts) . "\n"
                . "FROM companies c\n"
                . "LEFT JOIN subscriptions s ON s.company_id = c.id\n"
                . "ORDER BY c.created_at DESC";
        }

        return "SELECT c.id AS company_id, c.name AS company_name, c.code AS company_code, "
            . "'free' AS plan, 'active' AS status, "
            . "NULL AS invoice_limit, NULL AS storage_limit_bytes, "
            . "NULL AS included_tokens, NULL AS overage_per_1k_tokens_usd, NULL AS overage_per_invoice_usd, "
            . "NULL AS rate_limit_per_hour, NULL AS rate_limit_per_day "
            . "FROM companies c ORDER BY c.created_at DESC";
    }

    private function getSingleSql() {
        if ($this->isSubscriptionsTableAvailable()) {
            $selectParts = [
                'c.id AS company_id',
                'c.name AS company_name',
                'c.code AS company_code',
                $this->selectFragment('plan', 'plan', "'free'"),
                $this->selectFragment('status', 'status', "'active'"),
                $this->selectFragment('invoice_limit', 'invoice_limit'),
                $this->selectFragment('storage_limit_bytes', 'storage_limit_bytes'),
                $this->selectFragment('included_tokens', 'included_tokens'),
                $this->selectFragment('overage_per_1k_tokens_usd', 'overage_per_1k_tokens_usd'),
                $this->selectFragment('overage_per_invoice_usd', 'overage_per_invoice_usd'),
                $this->selectFragment('rate_limit_per_hour', 'rate_limit_per_hour'),
                $this->selectFragment('rate_limit_per_day', 'rate_limit_per_day'),
            ];

            return "SELECT " . implode(', ', $selectParts) . "\n"
                . "FROM companies c\n"
                . "LEFT JOIN subscriptions s ON s.company_id = c.id\n"
                . "WHERE c.id = :companyId";
        }

        return "SELECT c.id AS company_id, c.name AS company_name, c.code AS company_code, "
            . "'free' AS plan, 'active' AS status, "
            . "NULL AS invoice_limit, NULL AS storage_limit_bytes, "
            . "NULL AS included_tokens, NULL AS overage_per_1k_tokens_usd, NULL AS overage_per_invoice_usd, "
            . "NULL AS rate_limit_per_hour, NULL AS rate_limit_per_day "
            . "FROM companies c WHERE c.id = :companyId";
    }

    private function assertCompanyExists($companyId) {
        $stmt = $this->db->prepare("SELECT id FROM companies WHERE id = :id");
        $stmt->execute(['id' => $companyId]);
        if (!$stmt->fetch()) {
            sendJSON(['error' => 'Company not found'], 404);
        }
    }

    public function list() {
        requireRole('superadmin');

        $stmt = $this->db->query($this->getListSql());
        $rows = $stmt->fetchAll();
        $items = array_map([$this, 'formatSubscriptionRow'], $rows);

        sendJSON(['subscriptions' => $items]);
    }

    public function get($companyId) {
        requireRole('superadmin');

        $stmt = $this->db->prepare($this->getSingleSql());
        $stmt->execute(['companyId' => $companyId]);
        $row = $stmt->fetch();

        if (!$row) {
            sendJSON(['error' => 'Company not found'], 404);
        }

        sendJSON(['subscription' => $this->formatSubscriptionRow($row)]);
    }

    public function update($companyId) {
        requireRole('superadmin');

        if (!$companyId) {
            sendJSON(['error' => 'Company ID is required'], 400);
        }

        $this->assertCompanyExists($companyId);
        $data = $this->getRequestBody();

        if (empty($data)) {
            sendJSON(['error' => 'No data provided'], 400);
        }

        if (!$this->isSubscriptionsTableAvailable()) {
            sendJSON(['error' => 'Subscriptions table not available'], 500);
        }

        $normalized = $this->normalizeUpdatePayload($data);
        $writableColumns = array_filter(
            array_keys($normalized),
            fn($column) => $this->hasSubscriptionColumn($column)
        );

        $selectStmt = $this->db->prepare("SELECT company_id FROM subscriptions WHERE company_id = :companyId");
        $selectStmt->execute(['companyId' => $companyId]);
        $exists = (bool)$selectStmt->fetch();

        if ($exists) {
            if (!empty($writableColumns)) {
                $sets = [];
                $params = ['companyId' => $companyId];

                foreach ($writableColumns as $column) {
                    $sets[] = "$column = :$column";
                    $params[$column] = $normalized[$column];
                }

                if ($this->hasSubscriptionColumn('updated_at')) {
                    $sets[] = "updated_at = NOW()";
                }

                $sql = "UPDATE subscriptions SET " . implode(', ', $sets) . " WHERE company_id = :companyId";
                $this->db->prepare($sql)->execute($params);
            }
        } else {
            $defaults = $this->getDefaultSubscriptionValues();
            $insertData = [];

            if ($this->hasSubscriptionColumn('id')) {
                $insertData['id'] = generateId();
            }
            if ($this->hasSubscriptionColumn('company_id')) {
                $insertData['company_id'] = $companyId;
            }

            if ($this->hasSubscriptionColumn('plan')) {
                $insertData['plan'] = array_key_exists('plan', $normalized)
                    ? $normalized['plan']
                    : $defaults['plan'];
            }
            if ($this->hasSubscriptionColumn('status')) {
                $insertData['status'] = array_key_exists('status', $normalized)
                    ? $normalized['status']
                    : $defaults['status'];
            }

            foreach (['invoice_limit', 'storage_limit_bytes', 'included_tokens', 'overage_per_1k_tokens_usd', 'overage_per_invoice_usd'] as $column) {
                if ($this->hasSubscriptionColumn($column) && array_key_exists($column, $normalized)) {
                    $insertData[$column] = $normalized[$column];
                }
            }

            if (!empty($insertData)) {
                $columns = array_keys($insertData);
                $placeholders = array_map(fn($column) => ":$column", $columns);
                $sql = "INSERT INTO subscriptions (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
                $this->db->prepare($sql)->execute($insertData);
            }
        }

        $this->get($companyId);
    }

    public function create() {
        sendJSON(['error' => 'Method not allowed'], 405);
    }

    public function delete($id) {
        sendJSON(['error' => 'Method not allowed'], 405);
    }
}
