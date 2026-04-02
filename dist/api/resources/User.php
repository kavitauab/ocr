<?php
class User extends BaseResource {
    protected $tableName = 'users';

    public function list() {
        requireRole('superadmin');
        $stmt = $this->db->query("SELECT id, name, email, role, created_at FROM users ORDER BY created_at DESC");
        sendJSON(['users' => $stmt->fetchAll()]);
    }

    public function get($id) {
        requireRole('superadmin');
        $stmt = $this->db->prepare("SELECT id, name, email, role, created_at FROM users WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $user = $stmt->fetch();
        if (!$user) sendJSON(['error' => 'User not found'], 404);
        sendJSON(['user' => $user]);
    }

    public function create() {
        requireRole('superadmin');
        $data = $this->getRequestBody();
        if (empty($data['name']) || empty($data['email']) || empty($data['password'])) {
            sendJSON(['error' => 'Name, email, and password are required'], 400);
        }

        $id = generateId();
        $passwordHash = password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => 12]);
        $role = ($data['role'] ?? 'user') === 'superadmin' ? 'superadmin' : 'user';

        $this->db->prepare("INSERT INTO users (id, name, email, password_hash, role) VALUES (:id, :name, :email, :passwordHash, :role)")
            ->execute(['id' => $id, 'name' => $data['name'], 'email' => $data['email'], 'passwordHash' => $passwordHash, 'role' => $role]);

        sendJSON(['user' => ['id' => $id, 'name' => $data['name'], 'email' => $data['email'], 'role' => $role]], 201);
    }

    public function update($id) {
        $user = getAuthUser();
        // Allow users to edit their own profile, superadmins can edit anyone
        if ($user['id'] !== $id) requireRole('superadmin');

        $data = $this->getRequestBody();

        $sets = ['updated_at = NOW()'];
        $params = ['id' => $id];

        if (isset($data['name'])) { $sets[] = "name = :name"; $params['name'] = $data['name']; }
        if (isset($data['email'])) { $sets[] = "email = :email"; $params['email'] = $data['email']; }
        // Only superadmins can change roles
        if (isset($data['role']) && $user['role'] === 'superadmin') { $sets[] = "role = :role"; $params['role'] = $data['role']; }
        if (!empty($data['password'])) { $sets[] = "password_hash = :hash"; $params['hash'] = password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => 12]); }

        $this->db->prepare("UPDATE users SET " . implode(', ', $sets) . " WHERE id = :id")->execute($params);

        $stmt = $this->db->prepare("SELECT id, name, email, role FROM users WHERE id = :id");
        $stmt->execute(['id' => $id]);
        sendJSON(['user' => $stmt->fetch()]);
    }

    public function delete($id) {
        requireRole('superadmin');
        $this->db->prepare("DELETE FROM users WHERE id = :id")->execute(['id' => $id]);
        sendJSON(['success' => true]);
    }

    public function search($id = null) {
        requireRole('superadmin');
        $q = $_GET['q'] ?? '';
        if (strlen($q) < 2) sendJSON(['users' => []]);
        $stmt = $this->db->prepare("SELECT id, name, email FROM users WHERE name LIKE :q OR email LIKE :q2 ORDER BY name LIMIT 10");
        $stmt->execute(['q' => "%$q%", 'q2' => "%$q%"]);
        sendJSON(['users' => $stmt->fetchAll()]);
    }

    // Called for /api/user/companies
    public function companies($id = null) {
        $user = getAuthUser();
        $companies = getUserCompanies($user);
        sendJSON(['companies' => array_map('snakeToCamel', array_map('maskCompanySecrets', $companies))]);
    }
}
