<?php
class Email extends BaseResource {
    protected $tableName = 'email_inbox';

    private function getMetricsScope($defaultPeriod = 'daily') {
        $period = strtolower(trim((string)($_GET['period'] ?? $defaultPeriod)));
        if (!in_array($period, ['daily', 'weekly', 'monthly', 'custom'], true)) {
            $period = $defaultPeriod;
        }

        $dateFrom = trim((string)($_GET['dateFrom'] ?? ''));
        $dateTo = trim((string)($_GET['dateTo'] ?? ''));
        $hasCustomDates = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo);

        if ($hasCustomDates) {
            $period = 'custom';
            $startDate = $dateFrom;
            $endDate = $dateTo;
        } else {
            $today = new \DateTimeImmutable('today');
            if ($period === 'daily') {
                $startDate = $today->format('Y-m-d');
                $endDate = $today->format('Y-m-d');
            } elseif ($period === 'weekly') {
                $startDate = $today->modify('-6 days')->format('Y-m-d');
                $endDate = $today->format('Y-m-d');
            } else {
                $period = 'monthly';
                $startDate = $today->modify('-29 days')->format('Y-m-d');
                $endDate = $today->format('Y-m-d');
            }
        }

        if ($startDate > $endDate) {
            [$startDate, $endDate] = [$endDate, $startDate];
        }

        return [
            'period' => $period,
            'dateFrom' => $startDate,
            'dateTo' => $endDate,
            'startDateTime' => $startDate . ' 00:00:00',
            'endExclusiveDateTime' => (new \DateTimeImmutable($endDate . ' 00:00:00'))->modify('+1 day')->format('Y-m-d H:i:s'),
        ];
    }

    public function list() {
        $user = getAuthUser();
        $companyId = $_GET['companyId'] ?? '';
        $status = $_GET['status'] ?? '';
        $search = trim((string)($_GET['search'] ?? ''));
        $page = max(1, intval($_GET['page'] ?? 1));
        $limit = min(100, max(1, intval($_GET['limit'] ?? 50)));
        $offset = ($page - 1) * $limit;
        $scope = $this->getMetricsScope('daily');

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

        $conditions[] = "e.received_date >= :scopeStart AND e.received_date < :scopeEnd";
        $params['scopeStart'] = $scope['startDateTime'];
        $params['scopeEnd'] = $scope['endExclusiveDateTime'];

        if ($search !== '') {
            $conditions[] = "(e.subject LIKE :search OR e.from_name LIKE :searchFromName OR e.from_email LIKE :searchFromEmail)";
            $params['search'] = "%$search%";
            $params['searchFromName'] = "%$search%";
            $params['searchFromEmail'] = "%$search%";
        }

        $where = 'WHERE ' . implode(' AND ', $conditions);

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM email_inbox e $where");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $this->db->prepare("SELECT e.*, c.name as company_name FROM email_inbox e LEFT JOIN companies c ON e.company_id = c.id $where ORDER BY e.created_at DESC LIMIT $limit OFFSET $offset");
        $stmt->execute($params);
        $emails = $stmt->fetchAll();

        // Enrich each email with its attachment/invoice details
        $db = $this->db;
        $enriched = array_map(function($email) use ($db) {
            $email = snakeToCamel($email);
            // Get invoices linked to this email
            $invStmt = $db->prepare("SELECT id, original_filename, status, document_type, skip_reason, additional_files FROM invoices WHERE email_inbox_id = :eid ORDER BY created_at");
            $invStmt->execute(['eid' => $email['id']]);
            $invoices = $invStmt->fetchAll();
            $atts = [];
            foreach ($invoices as $inv) {
                $atts[] = [
                    'invoiceId' => $inv['id'],
                    'filename' => $inv['original_filename'],
                    'status' => $inv['status'],
                    'documentType' => $inv['document_type'],
                    'skipReason' => $inv['skip_reason'],
                ];
                // Include additional files linked to this invoice
                $af = json_decode($inv['additional_files'] ?? '[]', true);
                if (is_array($af)) {
                    foreach ($af as $f) {
                        $atts[] = [
                            'invoiceId' => $inv['id'],
                            'filename' => $f['filename'],
                            'status' => 'additional',
                            'documentType' => $f['documentType'] ?? null,
                            'skipReason' => $f['documentDetail'] ?? null,
                        ];
                    }
                }
            }
            $email['attachments'] = $atts;
            return $email;
        }, $emails);

        sendJSON([
            'emails' => $enriched,
            'total' => $total,
            'page' => $page,
            'totalPages' => max(1, (int)ceil($total / $limit)),
            'filters' => [
                'period' => $scope['period'],
                'dateFrom' => $scope['dateFrom'],
                'dateTo' => $scope['dateTo'],
                'search' => $search,
                'status' => $status,
            ],
        ]);
    }
}
