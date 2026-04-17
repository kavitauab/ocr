<?php
abstract class BaseResource {
    protected $db;
    protected $tableName;
    protected $idField = 'id';
    protected $allowedOrderColumns = ['id', 'created_at', 'updated_at'];
    protected $searchColumns = [];

    public function __construct() {
        $this->db = getDBConnection();
    }

    protected function getRequestBody() {
        return json_decode(file_get_contents('php://input'), true) ?: [];
    }

    protected function validateOrderField($field) {
        $field = preg_replace('/[^a-zA-Z0-9_]/', '', $field);
        if (in_array($field, $this->allowedOrderColumns)) return $field;
        return $this->allowedOrderColumns[0] ?? 'id';
    }

    protected function processItem($item) { return $item; }

    public function list() {
        $orderBy = $_GET['orderBy'] ?? '-created_at';
        $orderField = $this->validateOrderField(ltrim($orderBy, '-'));
        $orderDir = strpos($orderBy, '-') === 0 ? 'DESC' : 'ASC';

        $where = '';
        $params = [];

        $search = $_GET['search'] ?? '';
        if ($search && !empty($this->searchColumns)) {
            $conditions = [];
            foreach ($this->searchColumns as $i => $col) {
                $conditions[] = "`$col` LIKE :search$i";
                $params["search$i"] = "%$search%";
            }
            $where = 'WHERE (' . implode(' OR ', $conditions) . ')';
        }

        $page = max(1, intval($_GET['page'] ?? 1));
        // Cap per-page at 200 — prevents someone from requesting `?per_page=1000`
        // on tables like audit_log and overwhelming memory. Most pages use 20-50.
        $perPage = min(200, max(10, intval($_GET['per_page'] ?? $_GET['limit'] ?? 100)));
        $offset = ($page - 1) * $perPage;

        $countSql = "SELECT COUNT(*) FROM `{$this->tableName}` $where";
        $stmt = $this->db->prepare($countSql);
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        $sql = "SELECT * FROM `{$this->tableName}` $where ORDER BY `$orderField` $orderDir LIMIT $perPage OFFSET $offset";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $items = array_map([$this, 'processItem'], $stmt->fetchAll());

        sendJSON(['data' => $items, 'total' => $total, 'page' => $page, 'totalPages' => max(1, (int)ceil($total / $perPage))]);
    }

    public function get($id) {
        $stmt = $this->db->prepare("SELECT * FROM `{$this->tableName}` WHERE `{$this->idField}` = :id");
        $stmt->execute(['id' => $id]);
        $item = $stmt->fetch();
        if (!$item) sendJSON(['error' => 'Not found'], 404);
        sendJSON($this->processItem($item));
    }

    public function create() {
        $data = $this->getRequestBody();
        if (empty($data)) sendJSON(['error' => 'No data provided'], 400);

        $data = $this->beforeCreate($data);
        $columns = array_keys($data);
        $placeholders = array_map(fn($c) => ":$c", $columns);

        $sql = "INSERT INTO `{$this->tableName}` (`" . implode('`, `', $columns) . "`) VALUES (" . implode(', ', $placeholders) . ")";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($data);

        $id = isset($data['id']) ? $data['id'] : $this->db->lastInsertId();
        $this->afterCreate($id, $data);
        $this->get($id);
    }

    public function update($id) {
        $data = $this->getRequestBody();
        if (empty($data)) sendJSON(['error' => 'No data provided'], 400);

        $data = $this->beforeUpdate($id, $data);
        unset($data[$this->idField]);

        $sets = array_map(fn($c) => "`$c` = :$c", array_keys($data));
        $data['_id'] = $id;

        $sql = "UPDATE `{$this->tableName}` SET " . implode(', ', $sets) . " WHERE `{$this->idField}` = :_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($data);

        $this->afterUpdate($id, $data);
        $this->get($id);
    }

    public function delete($id) {
        $this->beforeDelete($id);
        $stmt = $this->db->prepare("DELETE FROM `{$this->tableName}` WHERE `{$this->idField}` = :id");
        $stmt->execute(['id' => $id]);
        if ($stmt->rowCount() === 0) sendJSON(['error' => 'Not found'], 404);
        sendJSON(['success' => true]);
    }

    protected function beforeCreate($data) { return $data; }
    protected function afterCreate($id, $data) {}
    protected function beforeUpdate($id, $data) { return $data; }
    protected function afterUpdate($id, $data) {}
    protected function beforeDelete($id) {}
}
