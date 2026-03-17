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
