<?php
/**
 * PaymentModule - User Model
 * /app/models/UserModel.php
 */

declare(strict_types=1);

namespace App\Models;

use PDO;

class UserModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = db();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function findByUuid(string $uuid): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE uuid = :uuid LIMIT 1');
        $stmt->execute([':uuid' => $uuid]);
        return $stmt->fetch() ?: null;
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        return $stmt->fetch() ?: null;
    }

    public function create(array $data): array
    {
        $uuid = generateUUID();
        $stmt = $this->db->prepare(
            'INSERT INTO users (uuid, name, email, password, role, status, email_verified_at)
             VALUES (:uuid, :name, :email, :pass, :role, :status, :evat)'
        );
        $stmt->execute([
            ':uuid'   => $uuid,
            ':name'   => $data['name'],
            ':email'  => $data['email'],
            ':pass'   => $data['password'],
            ':role'   => $data['role']   ?? 'user',
            ':status' => $data['status'] ?? 'pending',
            ':evat'   => $data['email_verified_at'] ?? null,
        ]);
        return $this->findById((int) $this->db->lastInsertId());
    }

    public function update(int $id, array $data): bool
    {
        $allowed = ['name', 'email', 'status', 'stripe_customer_id', 'paypal_customer_id', 'currency', 'timezone', 'avatar'];
        $sets    = [];
        $params  = [':id' => $id];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $sets[]            = "`$field` = :$field";
                $params[":$field"] = $data[$field];
            }
        }
        if (empty($sets)) return false;
        $stmt = $this->db->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = :id');
        return $stmt->execute($params);
    }

    public function updateLastLogin(int $id, string $ip): void
    {
        $this->db->prepare('UPDATE users SET last_login_at = NOW(), last_login_ip = :ip WHERE id = :id')
            ->execute([':ip' => $ip, ':id' => $id]);
    }

    public function list(int $limit = 50, int $offset = 0, array $filters = []): array
    {
        $where  = [];
        $params = [];
        if (!empty($filters['status'])) {
            $where[]           = 'status = :status';
            $params[':status'] = $filters['status'];
        }
        if (!empty($filters['role'])) {
            $where[]         = 'role = :role';
            $params[':role'] = $filters['role'];
        }
        if (!empty($filters['search'])) {
            $where[]           = '(name LIKE :s OR email LIKE :s2)';
            $params[':s']      = '%' . $filters['search'] . '%';
            $params[':s2']     = '%' . $filters['search'] . '%';
        }

        $sql  = 'SELECT id, uuid, name, email, role, status, currency, last_login_at, created_at FROM users';
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY created_at DESC LIMIT :lim OFFSET :off';

        $stmt = $this->db->prepare($sql);
        foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function count(array $filters = []): int
    {
        $where  = [];
        $params = [];
        if (!empty($filters['status'])) { $where[] = 'status = :status'; $params[':status'] = $filters['status']; }
        if (!empty($filters['role']))   { $where[] = 'role = :role';     $params[':role']   = $filters['role']; }

        $sql = 'SELECT COUNT(*) FROM users';
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }
}
