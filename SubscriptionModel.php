<?php
/**
 * PaymentModule - Subscription Model
 * /app/models/SubscriptionModel.php
 */

declare(strict_types=1);

namespace App\Models;

use PDO;

class SubscriptionModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = db();
    }

    public function create(array $data): array
    {
        $uuid = generateUUID();
        $stmt = $this->db->prepare(
            'INSERT INTO subscriptions
             (uuid, user_id, plan_id, provider, provider_sub_id, billing_cycle,
              status, current_period_start, current_period_end, trial_ends_at, coupon_id, discount_amount, metadata)
             VALUES (:uuid,:uid,:pid,:prov,:psid,:bc,:stat,:cps,:cpe,:te,:cid,:da,:meta)'
        );
        $stmt->execute([
            ':uuid' => $uuid,
            ':uid'  => $data['user_id'],
            ':pid'  => $data['plan_id'],
            ':prov' => $data['provider'],
            ':psid' => $data['provider_sub_id'] ?? null,
            ':bc'   => $data['billing_cycle'],
            ':stat' => $data['status'] ?? 'active',
            ':cps'  => $data['current_period_start'],
            ':cpe'  => $data['current_period_end'],
            ':te'   => $data['trial_ends_at'] ?? null,
            ':cid'  => $data['coupon_id'] ?? null,
            ':da'   => $data['discount_amount'] ?? 0,
            ':meta' => isset($data['metadata']) ? json_encode($data['metadata']) : null,
        ]);
        return $this->findById((int) $this->db->lastInsertId());
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT s.*, p.name as plan_name, p.price_monthly, p.price_yearly, p.features, p.type as plan_type
             FROM subscriptions s JOIN plans p ON p.id = s.plan_id WHERE s.id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function findByUuid(string $uuid): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT s.*, p.name as plan_name FROM subscriptions s
             JOIN plans p ON p.id = s.plan_id WHERE s.uuid = :uuid LIMIT 1'
        );
        $stmt->execute([':uuid' => $uuid]);
        return $stmt->fetch() ?: null;
    }

    public function activeForUser(int $userId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT s.*, p.name as plan_name, p.features, p.price_monthly, p.price_yearly
             FROM subscriptions s JOIN plans p ON p.id = s.plan_id
             WHERE s.user_id = :uid AND s.status IN ('active','trialing')
             ORDER BY s.created_at DESC LIMIT 1"
        );
        $stmt->execute([':uid' => $userId]);
        $row = $stmt->fetch();
        if ($row && isset($row['features']) && is_string($row['features'])) {
            $row['features'] = json_decode($row['features'], true);
        }
        return $row ?: null;
    }

    public function findCancelledForUser(int $userId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM subscriptions WHERE user_id = :uid
             AND status = 'active' AND cancel_at_period_end = 1
             ORDER BY created_at DESC LIMIT 1"
        );
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetch() ?: null;
    }

    public function listForUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT s.*, p.name as plan_name FROM subscriptions s
             JOIN plans p ON p.id = s.plan_id
             WHERE s.user_id = :uid ORDER BY s.created_at DESC'
        );
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetchAll();
    }

    public function cancel(int $id, bool $immediately = false): void
    {
        if ($immediately) {
            $this->db->prepare(
                "UPDATE subscriptions SET status = 'cancelled', cancelled_at = NOW(), ended_at = NOW()
                 WHERE id = :id"
            )->execute([':id' => $id]);
        } else {
            $this->db->prepare(
                "UPDATE subscriptions SET cancel_at_period_end = 1, cancelled_at = NOW() WHERE id = :id"
            )->execute([':id' => $id]);
        }
    }

    public function resume(int $id): void
    {
        $this->db->prepare(
            "UPDATE subscriptions SET cancel_at_period_end = 0, cancelled_at = NULL WHERE id = :id"
        )->execute([':id' => $id]);
    }

    public function updateStatus(int $id, string $status): void
    {
        $this->db->prepare('UPDATE subscriptions SET status = :s WHERE id = :id')
            ->execute([':s' => $status, ':id' => $id]);
    }

    public function findByProviderSubId(string $providerSubId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM subscriptions WHERE provider_sub_id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $providerSubId]);
        return $stmt->fetch() ?: null;
    }

    public function listAll(int $limit = 50, int $offset = 0, array $filters = []): array
    {
        $where  = [];
        $params = [];
        if (!empty($filters['status']))   { $where[] = 's.status = :status';   $params[':status']   = $filters['status']; }
        if (!empty($filters['provider'])) { $where[] = 's.provider = :prov';   $params[':prov']     = $filters['provider']; }

        $sql = 'SELECT s.*, p.name as plan_name, u.name as user_name, u.email as user_email
                FROM subscriptions s
                JOIN plans p ON p.id = s.plan_id
                JOIN users u ON u.id = s.user_id';
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY s.created_at DESC LIMIT :lim OFFSET :off';

        $stmt = $this->db->prepare($sql);
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':lim', $limit,  PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function countActive(): int
    {
        return (int) $this->db->query(
            "SELECT COUNT(*) FROM subscriptions WHERE status IN ('active','trialing')"
        )->fetchColumn();
    }
}
