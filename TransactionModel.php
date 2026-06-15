<?php
/**
 * PaymentModule - Transaction Model
 * /app/models/TransactionModel.php
 */

declare(strict_types=1);

namespace App\Models;

use PDO;

class TransactionModel
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
            'INSERT INTO transactions
             (uuid, user_id, subscription_id, plan_id, coupon_id, provider, provider_txn_id,
              provider_payment_intent, type, status, amount, tax_amount, discount_amount,
              net_amount, currency, description, payment_method, payment_method_last4,
              ip_address, user_agent, billing_name, billing_email, billing_address, metadata)
             VALUES
             (:uuid, :uid, :sid, :pid, :cid, :prov, :ptid, :ppi, :type, :stat, :amt, :tax,
              :disc, :net, :cur, :desc, :pm, :pml4, :ip, :ua, :bname, :bemail, :baddr, :meta)'
        );
        $stmt->execute([
            ':uuid'   => $uuid,
            ':uid'    => $data['user_id'],
            ':sid'    => $data['subscription_id'] ?? null,
            ':pid'    => $data['plan_id'] ?? null,
            ':cid'    => $data['coupon_id'] ?? null,
            ':prov'   => $data['provider'],
            ':ptid'   => $data['provider_txn_id'] ?? null,
            ':ppi'    => $data['provider_payment_intent'] ?? null,
            ':type'   => $data['type'] ?? 'payment',
            ':stat'   => $data['status'] ?? 'pending',
            ':amt'    => $data['amount'],
            ':tax'    => $data['tax_amount']      ?? 0,
            ':disc'   => $data['discount_amount'] ?? 0,
            ':net'    => $data['net_amount']       ?? $data['amount'],
            ':cur'    => $data['currency']         ?? 'USD',
            ':desc'   => $data['description']      ?? null,
            ':pm'     => $data['payment_method']       ?? null,
            ':pml4'   => $data['payment_method_last4'] ?? null,
            ':ip'     => $data['ip_address'] ?? getClientIp(),
            ':ua'     => $data['user_agent'] ?? substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ':bname'  => $data['billing_name']  ?? null,
            ':bemail' => $data['billing_email'] ?? null,
            ':baddr'  => isset($data['billing_address']) ? json_encode($data['billing_address']) : null,
            ':meta'   => isset($data['metadata']) ? json_encode($data['metadata']) : null,
        ]);
        return $this->findById((int) $this->db->lastInsertId());
    }

    public function update(int $id, array $data): bool
    {
        $allowed = [
            'provider_txn_id','provider_payment_intent','status','failure_code','failure_message',
            'payment_method','payment_method_last4','refunded_amount','metadata',
        ];
        $sets   = [];
        $params = [':id' => $id];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $data)) {
                $v = $f === 'metadata' && is_array($data[$f]) ? json_encode($data[$f]) : $data[$f];
                $sets[]           = "`$f` = :$f";
                $params[":$f"]    = $v;
            }
        }
        if (empty($sets)) return false;
        return $this->db->prepare('UPDATE transactions SET ' . implode(', ', $sets) . ' WHERE id = :id')
            ->execute($params);
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM transactions WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function findByUuid(string $uuid): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM transactions WHERE uuid = :uuid LIMIT 1');
        $stmt->execute([':uuid' => $uuid]);
        return $stmt->fetch() ?: null;
    }

    public function findByProviderTxnId(string $id, string $provider): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM transactions WHERE provider_txn_id = :id AND provider = :p LIMIT 1'
        );
        $stmt->execute([':id' => $id, ':p' => $provider]);
        return $stmt->fetch() ?: null;
    }

    public function listForUser(int $userId, int $limit = 20, int $offset = 0): array
    {
        $stmt = $this->db->prepare(
            'SELECT t.*, p.name as plan_name FROM transactions t
             LEFT JOIN plans p ON p.id = t.plan_id
             WHERE t.user_id = :uid
             ORDER BY t.created_at DESC
             LIMIT :lim OFFSET :off'
        );
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit,  PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function listAll(int $limit = 50, int $offset = 0, array $filters = []): array
    {
        $where  = [];
        $params = [];
        if (!empty($filters['status']))   { $where[] = 't.status = :status';   $params[':status']   = $filters['status']; }
        if (!empty($filters['provider'])) { $where[] = 't.provider = :prov';   $params[':prov']     = $filters['provider']; }
        if (!empty($filters['type']))     { $where[] = 't.type = :type';        $params[':type']     = $filters['type']; }
        if (!empty($filters['user_id']))  { $where[] = 't.user_id = :uid';      $params[':uid']      = $filters['user_id']; }
        if (!empty($filters['from']))     { $where[] = 't.created_at >= :from'; $params[':from']     = $filters['from']; }
        if (!empty($filters['to']))       { $where[] = 't.created_at <= :to';   $params[':to']       = $filters['to']; }

        $sql = 'SELECT t.*, u.name as user_name, u.email as user_email, p.name as plan_name
                FROM transactions t
                LEFT JOIN users u ON u.id = t.user_id
                LEFT JOIN plans p ON p.id = t.plan_id';
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY t.created_at DESC LIMIT :lim OFFSET :off';

        $stmt = $this->db->prepare($sql);
        foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
        $stmt->bindValue(':lim', $limit,  PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function totalRevenue(string $from = null, string $to = null): array
    {
        $where  = ["status = 'completed'"];
        $params = [];
        if ($from) { $where[] = 'created_at >= :from'; $params[':from'] = $from; }
        if ($to)   { $where[] = 'created_at <= :to';   $params[':to']   = $to; }

        $stmt = $this->db->prepare(
            'SELECT currency, SUM(net_amount) as total, COUNT(*) as count
             FROM transactions WHERE ' . implode(' AND ', $where) .
            ' GROUP BY currency'
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
