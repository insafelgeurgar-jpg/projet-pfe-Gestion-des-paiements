<?php
/**
 * PaymentModule - Plan Model
 * /app/models/PlanModel.php
 */

declare(strict_types=1);

namespace App\Models;

use PDO;

class PlanModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = db();
    }

    public function all(bool $activeOnly = true): array
    {
        $sql  = 'SELECT * FROM plans';
        if ($activeOnly) $sql .= ' WHERE is_active = 1';
        $sql .= ' ORDER BY sort_order ASC, id ASC';
        return $this->db->query($sql)->fetchAll();
    }

    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM plans WHERE slug = :slug AND is_active = 1 LIMIT 1');
        $stmt->execute([':slug' => $slug]);
        return $stmt->fetch() ?: null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM plans WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function findByUuid(string $uuid): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM plans WHERE uuid = :uuid LIMIT 1');
        $stmt->execute([':uuid' => $uuid]);
        return $stmt->fetch() ?: null;
    }

    public function create(array $data): array
    {
        $uuid = generateUUID();
        $stmt = $this->db->prepare(
            'INSERT INTO plans (uuid, name, slug, description, type, price_monthly, price_yearly, currency,
              trial_days, features, limits, is_featured, is_active, sort_order, stripe_monthly_price_id,
              stripe_yearly_price_id, paypal_monthly_plan_id, paypal_yearly_plan_id, metadata)
             VALUES (:uuid, :name, :slug, :desc, :type, :pm, :py, :cur, :td, :feat, :lim, :feat2, :active, :sort,
              :smp, :syp, :pmp, :pyp, :meta)'
        );
        $stmt->execute([
            ':uuid'  => $uuid,
            ':name'  => $data['name'],
            ':slug'  => $data['slug'],
            ':desc'  => $data['description'] ?? null,
            ':type'  => $data['type'] ?? 'subscription',
            ':pm'    => $data['price_monthly'] ?? 0,
            ':py'    => $data['price_yearly']  ?? 0,
            ':cur'   => $data['currency'] ?? 'USD',
            ':td'    => $data['trial_days'] ?? 0,
            ':feat'  => isset($data['features']) ? json_encode($data['features']) : null,
            ':lim'   => isset($data['limits'])   ? json_encode($data['limits'])   : null,
            ':feat2' => (int) ($data['is_featured'] ?? 0),
            ':active'=> (int) ($data['is_active']   ?? 1),
            ':sort'  => (int) ($data['sort_order']  ?? 0),
            ':smp'   => $data['stripe_monthly_price_id'] ?? null,
            ':syp'   => $data['stripe_yearly_price_id']  ?? null,
            ':pmp'   => $data['paypal_monthly_plan_id']  ?? null,
            ':pyp'   => $data['paypal_yearly_plan_id']   ?? null,
            ':meta'  => isset($data['metadata']) ? json_encode($data['metadata']) : null,
        ]);
        return $this->findById((int) $this->db->lastInsertId());
    }

    public function update(string $uuid, array $data): bool
    {
        $allowed = [
            'name','slug','description','type','price_monthly','price_yearly','currency',
            'trial_days','features','limits','is_featured','is_active','sort_order',
            'stripe_monthly_price_id','stripe_yearly_price_id','paypal_monthly_plan_id','paypal_yearly_plan_id',
        ];
        $sets   = [];
        $params = [':uuid' => $uuid];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $v = in_array($field, ['features','limits']) && is_array($data[$field])
                    ? json_encode($data[$field]) : $data[$field];
                $sets[]            = "`$field` = :$field";
                $params[":$field"] = $v;
            }
        }
        if (empty($sets)) return false;
        return $this->db->prepare('UPDATE plans SET ' . implode(', ', $sets) . ' WHERE uuid = :uuid')
            ->execute($params);
    }

    public function delete(string $uuid): bool
    {
        return $this->db->prepare('UPDATE plans SET is_active = 0 WHERE uuid = :uuid')
            ->execute([':uuid' => $uuid]);
    }

    public function decode(array $plan): array
    {
        foreach (['features','limits','metadata'] as $k) {
            if (isset($plan[$k]) && is_string($plan[$k])) {
                $plan[$k] = json_decode($plan[$k], true);
            }
        }
        return $plan;
    }
}
