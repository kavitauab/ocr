<?php
class Setting extends BaseResource {
    protected $tableName = 'settings';
    protected $idField = 'key';

    public function list() {
        requireRole('superadmin');
        $stmt = $this->db->query("SELECT * FROM settings");
        $items = $stmt->fetchAll();
        $result = [];
        foreach ($items as $item) {
            $key = $item['key'];
            $value = $item['value'];
            if (strpos($key, 'api_key') !== false && $value) {
                $result[$key] = substr($value, 0, 10) . '••••••••';
            } else {
                $result[$key] = $value;
            }
        }
        sendJSON(['settings' => $result]);
    }

    public function models($id = null) {
        requireRole('superadmin');
        $apiKey = getAnthropicApiKey();
        if (!$apiKey) sendJSON(['error' => 'API key not configured'], 400);

        $ch = curl_init('https://api.anthropic.com/v1/models');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'x-api-key: ' . $apiKey,
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_TIMEOUT => 15,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) sendJSON(['error' => 'Failed to fetch models'], 500);

        $data = json_decode($response, true);
        $models = [];
        foreach ($data['data'] ?? [] as $m) {
            $models[] = ['id' => $m['id'], 'name' => $m['display_name'] ?? $m['id'], 'created' => $m['created_at'] ?? null];
        }
        // Sort by name
        usort($models, fn($a, $b) => strcmp($a['name'], $b['name']));
        sendJSON(['models' => $models]);
    }

    public function update($id = null) {
        requireRole('superadmin');
        $data = $this->getRequestBody();

        foreach ($data as $key => $value) {
            if (!is_string($value)) continue;
            if (strpos($value, '••••••••') !== false) continue;

            $stmt = $this->db->prepare("SELECT `key` FROM settings WHERE `key` = :key");
            $stmt->execute(['key' => $key]);

            if ($stmt->fetch()) {
                $this->db->prepare("UPDATE settings SET `value` = :value WHERE `key` = :key")->execute(['value' => $value, 'key' => $key]);
            } else {
                $this->db->prepare("INSERT INTO settings (`key`, `value`) VALUES (:key, :value)")->execute(['key' => $key, 'value' => $value]);
            }
        }

        sendJSON(['success' => true]);
    }
}
