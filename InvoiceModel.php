<?php
/**
 * PaymentModule - Invoice Model
 * /app/models/InvoiceModel.php
 */

declare(strict_types=1);

namespace App\Models;

use PDO;

class InvoiceModel
{
    private PDO $db;
    public function __construct() { $this->db = db(); }

    public function create(array $data): array
    {
        $uuid   = generateUUID();
        $number = invoiceNumber();
        $stmt   = $this->db->prepare(
            'INSERT INTO invoices (uuid,number,user_id,transaction_id,subscription_id,status,
             subtotal,tax_rate,tax_amount,discount_amount,total,currency,due_date,paid_at,
             billing_details,line_items,notes)
             VALUES(:uuid,:num,:uid,:tid,:sid,:stat,:sub,:tr,:tax,:disc,:tot,:cur,:due,:paid,:bd,:li,:notes)'
        );
        $stmt->execute([
            ':uuid'  => $uuid,   ':num'   => $number,
            ':uid'   => $data['user_id'],
            ':tid'   => $data['transaction_id'],
            ':sid'   => $data['subscription_id'] ?? null,
            ':stat'  => $data['status'] ?? 'paid',
            ':sub'   => $data['subtotal'],
            ':tr'    => $data['tax_rate']        ?? 0,
            ':tax'   => $data['tax_amount']      ?? 0,
            ':disc'  => $data['discount_amount'] ?? 0,
            ':tot'   => $data['total'],
            ':cur'   => $data['currency'] ?? 'USD',
            ':due'   => $data['due_date'] ?? null,
            ':paid'  => $data['paid_at']  ?? null,
            ':bd'    => isset($data['billing_details']) ? json_encode($data['billing_details']) : null,
            ':li'    => isset($data['line_items'])      ? json_encode($data['line_items'])      : null,
            ':notes' => $data['notes'] ?? null,
        ]);
        return $this->findById((int) $this->db->lastInsertId());
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM invoices WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function findByUuid(string $uuid): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM invoices WHERE uuid = :uuid LIMIT 1');
        $stmt->execute([':uuid' => $uuid]);
        return $stmt->fetch() ?: null;
    }

    public function listForUser(int $userId, int $limit = 20, int $offset = 0): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM invoices WHERE user_id = :uid ORDER BY created_at DESC LIMIT :lim OFFSET :off'
        );
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit,  PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function updatePdfPath(int $id, string $path): void
    {
        $this->db->prepare('UPDATE invoices SET pdf_path = :p WHERE id = :id')
            ->execute([':p' => $path, ':id' => $id]);
    }
}

// ============================================================
// Refund Model
// ============================================================
namespace App\Models;

class RefundModel
{
    private \PDO $db;
    public function __construct() { $this->db = db(); }

    public function create(array $data): array
    {
        $uuid = generateUUID();
        $stmt = $this->db->prepare(
            'INSERT INTO refunds (uuid,transaction_id,user_id,processed_by,provider,provider_refund_id,
             amount,currency,reason,reason_detail,status,metadata)
             VALUES(:uuid,:tid,:uid,:pb,:prov,:prid,:amt,:cur,:reason,:rd,:stat,:meta)'
        );
        $stmt->execute([
            ':uuid'  => $uuid,
            ':tid'   => $data['transaction_id'],
            ':uid'   => $data['user_id'],
            ':pb'    => $data['processed_by'] ?? null,
            ':prov'  => $data['provider'],
            ':prid'  => $data['provider_refund_id'] ?? null,
            ':amt'   => $data['amount'],
            ':cur'   => $data['currency'] ?? 'USD',
            ':reason'=> $data['reason'] ?? 'requested_by_customer',
            ':rd'    => $data['reason_detail'] ?? null,
            ':stat'  => $data['status'] ?? 'pending',
            ':meta'  => isset($data['metadata']) ? json_encode($data['metadata']) : null,
        ]);
        $id   = (int) $this->db->lastInsertId();
        $stmt = $this->db->prepare('SELECT * FROM refunds WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }
}

// ============================================================
// Webhook Model
// ============================================================
namespace App\Models;

class WebhookModel
{
    private \PDO $db;
    public function __construct() { $this->db = db(); }

    public function store(array $data): int
    {
        $uuid = generateUUID();
        $stmt = $this->db->prepare(
            'INSERT INTO webhooks (uuid,provider,payload,headers,ip_address,status)
             VALUES(:uuid,:prov,:payload,:headers,:ip,:stat)'
        );
        $stmt->execute([
            ':uuid'    => $uuid,
            ':prov'    => $data['provider'],
            ':payload' => $data['payload'],
            ':headers' => $data['headers'] ?? null,
            ':ip'      => $data['ip_address'] ?? getClientIp(),
            ':stat'    => $data['status'] ?? 'received',
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $allowed = ['status','verified','event_type','event_id','processed_at','error','attempts'];
        $sets    = [];
        $params  = [':id' => $id];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $data)) {
                $sets[]          = "`$f` = :$f";
                $params[":$f"]   = $data[$f];
            }
        }
        if (!empty($sets)) {
            $this->db->prepare('UPDATE webhooks SET ' . implode(', ', $sets) . ' WHERE id = :id')
                ->execute($params);
        }
    }
}
