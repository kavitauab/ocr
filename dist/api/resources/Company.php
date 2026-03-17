<?php
require_once __DIR__ . '/../lib/audit.php';
require_once __DIR__ . '/../lib/microsoft_graph.php';
require_once __DIR__ . '/../lib/vecticum.php';
require_once __DIR__ . '/../lib/email_processor.php';

class Company extends BaseResource {
    protected $tableName = 'companies';

    public function list() {
        $user = getAuthUser();
        $companies = getUserCompanies($user);
        $masked = array_map('maskCompanySecrets', $companies);
        sendJSON(['companies' => $masked]);
    }

    public function get($id) {
        $user = getAuthUser();
        requireCompanyAccess($user, $id);
        $stmt = $this->db->prepare("SELECT * FROM companies WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $company = $stmt->fetch();
        if (!$company) sendJSON(['error' => 'Company not found'], 404);
        sendJSON(['company' => maskCompanySecrets($company)]);
    }

    public function create() {
        $user = getAuthUser();
        $data = $this->getRequestBody();
        if (empty($data['name']) || empty($data['code'])) sendJSON(['error' => 'Name and code are required'], 400);

        $id = generateId();
        $stmt = $this->db->prepare("INSERT INTO companies (id, name, code, logo_url) VALUES (:id, :name, :code, :logoUrl)");
        $stmt->execute(['id' => $id, 'name' => $data['name'], 'code' => $data['code'], 'logoUrl' => $data['logoUrl'] ?? null]);

        // Add creator as owner
        $this->db->prepare("INSERT INTO user_companies (id, user_id, company_id, role) VALUES (:id, :userId, :companyId, 'owner')")
            ->execute(['id' => generateId(), 'userId' => $user['id'], 'companyId' => $id]);

        logAction(['userId' => $user['id'], 'companyId' => $id, 'action' => 'create', 'resourceType' => 'company', 'resourceId' => $id]);

        $stmt = $this->db->prepare("SELECT * FROM companies WHERE id = :id");
        $stmt->execute(['id' => $id]);
        sendJSON(['company' => $stmt->fetch()], 201);
    }

    public function update($id) {
        $user = getAuthUser();
        requireCompanyAccess($user, $id, 'admin');

        $stmt = $this->db->prepare("SELECT * FROM companies WHERE id = :id");
        $stmt->execute(['id' => $id]);
        if (!$stmt->fetch()) sendJSON(['error' => 'Company not found'], 404);

        $data = $this->getRequestBody();
        $allowed = ['name', 'code', 'logo_url', 'ms_client_id', 'ms_client_secret', 'ms_tenant_id', 'ms_sender_email', 'ms_fetch_enabled', 'ms_fetch_folder', 'ms_fetch_interval_minutes', 'vecticum_enabled', 'vecticum_api_base_url', 'vecticum_client_id', 'vecticum_client_secret', 'vecticum_company_id', 'vecticum_author_id', 'vecticum_author_name'];

        // Handle camelCase from frontend
        $camelMap = [
            'logoUrl' => 'logo_url', 'msClientId' => 'ms_client_id', 'msClientSecret' => 'ms_client_secret',
            'msTenantId' => 'ms_tenant_id', 'msSenderEmail' => 'ms_sender_email',
            'msFetchEnabled' => 'ms_fetch_enabled', 'msFetchFolder' => 'ms_fetch_folder',
            'msFetchIntervalMinutes' => 'ms_fetch_interval_minutes',
            'vecticumEnabled' => 'vecticum_enabled', 'vecticumApiBaseUrl' => 'vecticum_api_base_url',
            'vecticumClientId' => 'vecticum_client_id', 'vecticumClientSecret' => 'vecticum_client_secret',
            'vecticumCompanyId' => 'vecticum_company_id', 'vecticumAuthorId' => 'vecticum_author_id',
            'vecticumAuthorName' => 'vecticum_author_name',
        ];

        $updates = ['updated_at' => date('Y-m-d H:i:s')];
        foreach ($data as $key => $value) {
            $dbKey = $camelMap[$key] ?? $key;
            if (in_array($dbKey, $allowed)) {
                if ($value === '••••••••') continue;
                $updates[$dbKey] = $value;
            }
        }

        $sets = [];
        $params = [];
        foreach ($updates as $key => $value) {
            $paramKey = str_replace('.', '_', $key);
            $sets[] = "`$key` = :$paramKey";
            $params[$paramKey] = $value;
        }
        $params['_id'] = $id;

        $this->db->prepare("UPDATE companies SET " . implode(', ', $sets) . " WHERE id = :_id")->execute($params);
        logAction(['userId' => $user['id'], 'companyId' => $id, 'action' => 'update', 'resourceType' => 'company', 'resourceId' => $id]);

        $stmt = $this->db->prepare("SELECT * FROM companies WHERE id = :id");
        $stmt->execute(['id' => $id]);
        sendJSON(['company' => maskCompanySecrets($stmt->fetch())]);
    }

    public function delete($id) {
        $user = getAuthUser();
        requireCompanyAccess($user, $id, 'owner');
        $this->db->prepare("DELETE FROM companies WHERE id = :id")->execute(['id' => $id]);
        logAction(['userId' => $user['id'], 'companyId' => $id, 'action' => 'delete', 'resourceType' => 'company', 'resourceId' => $id]);
        sendJSON(['success' => true]);
    }

    public function members($id) {
        $user = getAuthUser();
        requireCompanyAccess($user, $id);

        // Check for 4th URL segment (userId) for PATCH/DELETE on specific member
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $parts = array_values(array_filter(explode('/', preg_replace('#^/api#', '', $path))));
        $memberId = $parts[3] ?? null;

        if ($memberId) {
            $method = $_SERVER['REQUEST_METHOD'];
            if ($method === 'PATCH') {
                requireCompanyAccess($user, $id, 'admin');
                $data = $this->getRequestBody();
                $role = $data['role'] ?? '';
                if (!in_array($role, ['owner', 'admin', 'manager', 'viewer'])) sendJSON(['error' => 'Invalid role'], 400);

                $this->db->prepare("UPDATE user_companies SET role = :role WHERE user_id = :userId AND company_id = :companyId")
                    ->execute(['role' => $role, 'userId' => $memberId, 'companyId' => $id]);
                logAction(['userId' => $user['id'], 'companyId' => $id, 'action' => 'change_role', 'resourceType' => 'user', 'resourceId' => $memberId, 'metadata' => ['role' => $role]]);
                sendJSON(['success' => true]);
            } elseif ($method === 'DELETE') {
                requireCompanyAccess($user, $id, 'admin');
                if ($memberId === $user['id']) sendJSON(['error' => 'Cannot remove yourself'], 400);
                $this->db->prepare("DELETE FROM user_companies WHERE user_id = :userId AND company_id = :companyId")
                    ->execute(['userId' => $memberId, 'companyId' => $id]);
                logAction(['userId' => $user['id'], 'companyId' => $id, 'action' => 'remove_member', 'resourceType' => 'user', 'resourceId' => $memberId]);
                sendJSON(['success' => true]);
            }
        }

        $method = $_SERVER['REQUEST_METHOD'];
        if ($method === 'POST') {
            requireCompanyAccess($user, $id, 'admin');
            $data = $this->getRequestBody();
            $email = $data['email'] ?? '';
            $role = $data['role'] ?? 'viewer';
            if (!$email) sendJSON(['error' => 'Email is required'], 400);

            $stmt = $this->db->prepare("SELECT * FROM users WHERE email = :email");
            $stmt->execute(['email' => $email]);
            $targetUser = $stmt->fetch();
            if (!$targetUser) sendJSON(['error' => 'User not found. They must have an account first.'], 404);

            $stmt = $this->db->prepare("SELECT id FROM user_companies WHERE user_id = :userId AND company_id = :companyId");
            $stmt->execute(['userId' => $targetUser['id'], 'companyId' => $id]);
            if ($stmt->fetch()) sendJSON(['error' => 'User is already a member'], 409);

            $this->db->prepare("INSERT INTO user_companies (id, user_id, company_id, role) VALUES (:id, :userId, :companyId, :role)")
                ->execute(['id' => generateId(), 'userId' => $targetUser['id'], 'companyId' => $id, 'role' => $role]);
            logAction(['userId' => $user['id'], 'companyId' => $id, 'action' => 'add_member', 'resourceType' => 'user', 'resourceId' => $targetUser['id'], 'metadata' => ['role' => $role, 'email' => $email]]);
            sendJSON(['success' => true], 201);
        }

        // GET members list
        $stmt = $this->db->prepare("SELECT u.id as userId, u.name, u.email, uc.role, uc.created_at as joinedAt FROM user_companies uc INNER JOIN users u ON u.id = uc.user_id WHERE uc.company_id = :companyId");
        $stmt->execute(['companyId' => $id]);
        sendJSON(['members' => $stmt->fetchAll()]);
    }

    public function usage($id) {
        $user = getAuthUser();
        requireCompanyAccess($user, $id);
        $stmt = $this->db->prepare("SELECT * FROM usage_logs WHERE company_id = :companyId ORDER BY month DESC LIMIT 12");
        $stmt->execute(['companyId' => $id]);
        sendJSON(['usage' => $stmt->fetchAll()]);
    }

    // Using audit_log as method name with underscore
    public function audit($id) {
        $user = getAuthUser();
        requireCompanyAccess($user, $id, 'admin');
        $limit = min(100, max(1, intval($_GET['limit'] ?? 50)));

        $stmt = $this->db->prepare("SELECT a.id, a.action, a.resource_type, a.resource_id, a.metadata, a.created_at, u.name as user_name, u.email as user_email FROM audit_log a LEFT JOIN users u ON u.id = a.user_id WHERE a.company_id = :companyId ORDER BY a.created_at DESC LIMIT $limit");
        $stmt->execute(['companyId' => $id]);
        $logs = $stmt->fetchAll();

        foreach ($logs as &$log) {
            if (isset($log['metadata'])) $log['metadata'] = json_decode($log['metadata'], true);
        }

        sendJSON(['logs' => $logs]);
    }

    public function test_email($id) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') sendJSON(['error' => 'Method not allowed'], 405);
        $user = getAuthUser();
        requireCompanyAccess($user, $id, 'admin');

        $stmt = $this->db->prepare("SELECT * FROM companies WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $company = $stmt->fetch();
        if (!$company) sendJSON(['error' => 'Company not found'], 404);

        sendJSON(testM365Connection($company));
    }

    public function test_vecticum($id) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') sendJSON(['error' => 'Method not allowed'], 405);
        $user = getAuthUser();
        requireCompanyAccess($user, $id, 'admin');

        $stmt = $this->db->prepare("SELECT * FROM companies WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $company = $stmt->fetch();
        if (!$company) sendJSON(['error' => 'Company not found'], 404);

        sendJSON(testVecticumConnection($company));
    }

    public function fetch_emails($id) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') sendJSON(['error' => 'Method not allowed'], 405);
        $user = getAuthUser();
        requireCompanyAccess($user, $id, 'manager');

        sendJSON(processCompanyEmails($id));
    }
}
