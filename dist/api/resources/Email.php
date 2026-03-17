<?php
class Email extends BaseResource {
    protected $tableName = 'email_inbox';

    public function list() {
        $user = getAuthUser();
        $companyId = $_GET['companyId'] ?? '';
        $status = $_GET['status'] ?? '';
        $page = max(1, intval($_GET['page'] ?? 1));
        $limit = min(100, max(1, intval($_GET['limit'] ?? 50)));
        $offset = ($page - 1) * $limit;

        $conditions = [];
        $params = [];

        $userCompanies = getUserCompanies($user);
        $allowedIds = array_column($userCompanies, 'id');

        if ($companyId) {
            if (!in_array($companyId, $allowedIds)) sendJSON(['error' => 'Access denied'], 403);
            $conditions[] = "e.company_id = :companyId";
            $params['companyId'] = $companyId;
        } elseif (!empty($allowedIds)) {
            $placeholders = [];
            foreach ($allowedIds as $idx => $cid) {
                $placeholders[] = ":cid$idx";
                $params["cid$idx"] = $cid;
            }
            $conditions[] = "e.company_id IN (" . implode(',', $placeholders) . ")";
        } else {
            sendJSON(['emails' => [], 'total' => 0, 'page' => $page, 'totalPages' => 0]);
        }

        if ($status) {
            $conditions[] = "e.status = :status";
            $params['status'] = $status;
        }

        $where = 'WHERE ' . implode(' AND ', $conditions);

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM email_inbox e $where");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $this->db->prepare("SELECT e.*, c.name as company_name FROM email_inbox e LEFT JOIN companies c ON e.company_id = c.id $where ORDER BY e.created_at DESC LIMIT $limit OFFSET $offset");
        $stmt->execute($params);

        sendJSON(['emails' => $stmt->fetchAll(), 'total' => $total, 'page' => $page, 'totalPages' => max(1, (int)ceil($total / $limit))]);
    }
}
