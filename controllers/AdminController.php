<?php
/**
 * PaymentModule - Admin Controller
 * /app/controllers/AdminController.php
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Middleware\AuthMiddleware;
use App\Middleware\RateLimitMiddleware;
use App\Models\{UserModel, PlanModel, TransactionModel, SubscriptionModel, InvoiceModel, RefundModel};
use App\Providers\ProviderFactory;
use App\Services\InvoiceService;

class AdminController
{
    private function admin(): array
    {
        return (new AuthMiddleware())->requireAdmin();
    }

    // ── GET /api/admin/dashboard ──────────────────────────────────────────
    public function dashboard(array $params): void
    {
        $this->admin();
        $pdo = db();

        $totalRevenue = $pdo->query(
            "SELECT COALESCE(SUM(net_amount),0) FROM transactions WHERE status='completed'"
        )->fetchColumn();

        $todayRevenue = $pdo->query(
            "SELECT COALESCE(SUM(net_amount),0) FROM transactions WHERE status='completed' AND DATE(created_at)=CURDATE()"
        )->fetchColumn();

        $monthRevenue = $pdo->query(
            "SELECT COALESCE(SUM(net_amount),0) FROM transactions WHERE status='completed'
             AND MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())"
        )->fetchColumn();

        $totalUsers       = (new UserModel())->count();
        $activeUsers      = (new UserModel())->count(['status' => 'active']);
        $activeSubs       = (new SubscriptionModel())->countActive();
        $pendingTxns      = (int) $pdo->query("SELECT COUNT(*) FROM transactions WHERE status='pending'")->fetchColumn();
        $failedTxns       = (int) $pdo->query("SELECT COUNT(*) FROM transactions WHERE status='failed'")->fetchColumn();

        // Revenue last 12 months
        $monthlyRevenue = $pdo->query(
            "SELECT DATE_FORMAT(created_at,'%Y-%m') as month,
                    COALESCE(SUM(net_amount),0) as revenue,
                    COUNT(*) as count
             FROM transactions WHERE status='completed' AND created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
             GROUP BY DATE_FORMAT(created_at,'%Y-%m') ORDER BY month ASC"
        )->fetchAll();

        // Top plans
        $topPlans = $pdo->query(
            "SELECT p.name, COUNT(t.id) as sales, COALESCE(SUM(t.net_amount),0) as revenue
             FROM transactions t JOIN plans p ON p.id=t.plan_id
             WHERE t.status='completed' GROUP BY p.id ORDER BY revenue DESC LIMIT 5"
        )->fetchAll();

        // Recent transactions
        $recentTxns = $pdo->query(
            "SELECT t.uuid, t.amount, t.currency, t.status, t.provider, t.created_at,
                    u.name as user_name, u.email as user_email
             FROM transactions t JOIN users u ON u.id=t.user_id
             ORDER BY t.created_at DESC LIMIT 10"
        )->fetchAll();

        successResponse([
            'revenue' => [
                'total'   => (float) $totalRevenue,
                'today'   => (float) $todayRevenue,
                'month'   => (float) $monthRevenue,
            ],
            'counts' => [
                'users'        => $totalUsers,
                'active_users' => $activeUsers,
                'active_subs'  => $activeSubs,
                'pending_txns' => $pendingTxns,
                'failed_txns'  => $failedTxns,
            ],
            'monthly_revenue' => $monthlyRevenue,
            'top_plans'       => $topPlans,
            'recent_transactions' => $recentTxns,
        ]);
    }

    // ── GET /api/admin/transactions ───────────────────────────────────────
    public function transactions(array $params): void
    {
        $this->admin();
        $page    = max(1, (int) ($_GET['page']   ?? 1));
        $limit   = min(100, max(10, (int) ($_GET['limit'] ?? 25)));
        $offset  = ($page - 1) * $limit;
        $filters = array_filter([
            'status'   => $_GET['status']   ?? '',
            'provider' => $_GET['provider'] ?? '',
            'type'     => $_GET['type']     ?? '',
            'from'     => $_GET['from']     ?? '',
            'to'       => $_GET['to']       ?? '',
        ]);

        $txnModel = new TransactionModel();
        $txns     = $txnModel->listAll($limit, $offset, $filters);

        $total = (int) db()->query("SELECT COUNT(*) FROM transactions")->fetchColumn();
        successResponse(['transactions' => $txns, 'total' => $total, 'page' => $page, 'limit' => $limit]);
    }

    // ── GET /api/admin/transactions/:uuid ─────────────────────────────────
    public function transactionDetail(array $params): void
    {
        $this->admin();
        $txn = (new TransactionModel())->findByUuid($params['uuid']);
        if (!$txn) errorResponse('Transaction not found.', 404);
        successResponse($txn);
    }

    // ── GET /api/admin/users ──────────────────────────────────────────────
    public function users(array $params): void
    {
        $this->admin();
        $page   = max(1, (int) ($_GET['page']  ?? 1));
        $limit  = min(100, max(10, (int) ($_GET['limit'] ?? 25)));
        $offset = ($page - 1) * $limit;
        $filters = array_filter([
            'status' => $_GET['status'] ?? '',
            'role'   => $_GET['role']   ?? '',
            'search' => $_GET['search'] ?? '',
        ]);

        $userModel = new UserModel();
        $users     = $userModel->list($limit, $offset, $filters);
        $total     = $userModel->count($filters);
        successResponse(['users' => $users, 'total' => $total, 'page' => $page, 'limit' => $limit]);
    }

    // ── GET /api/admin/users/:uuid ────────────────────────────────────────
    public function userDetail(array $params): void
    {
        $this->admin();
        $user = (new UserModel())->findByUuid($params['uuid']);
        if (!$user) errorResponse('User not found.', 404);

        unset($user['password'], $user['two_factor_secret']);

        $sub  = (new SubscriptionModel())->activeForUser($user['id']);
        $txns = (new TransactionModel())->listForUser($user['id'], 5, 0);

        successResponse(['user' => $user, 'subscription' => $sub, 'recent_transactions' => $txns]);
    }

    // ── PUT /api/admin/users/:uuid ────────────────────────────────────────
    public function updateUser(array $params): void
    {
        $this->admin();
        $body = getRequestBody();
        $user = (new UserModel())->findByUuid($params['uuid']);
        if (!$user) errorResponse('User not found.', 404);

        $allowed = ['status', 'role', 'name'];
        $data    = array_intersect_key($body, array_flip($allowed));
        (new UserModel())->update($user['id'], $data);

        successResponse(null, 'User updated.');
    }

    // ── GET /api/admin/subscriptions ──────────────────────────────────────
    public function subscriptions(array $params): void
    {
        $this->admin();
        $page   = max(1, (int) ($_GET['page']  ?? 1));
        $limit  = min(100, max(10, (int) ($_GET['limit'] ?? 25)));
        $offset = ($page - 1) * $limit;

        $subs  = (new SubscriptionModel())->listAll($limit, $offset);
        $total = (int) db()->query("SELECT COUNT(*) FROM subscriptions")->fetchColumn();
        successResponse(['subscriptions' => $subs, 'total' => $total, 'page' => $page, 'limit' => $limit]);
    }

    // ── POST /api/admin/refund ────────────────────────────────────────────
    public function refund(array $params): void
    {
        (new RateLimitMiddleware(10, 60))->handle();
        $admin = $this->admin();
        $body  = getRequestBody();
        $errors = validateRequired($body, ['transaction_uuid', 'amount']);
        if (!empty($errors)) errorResponse('Validation failed.', 422, $errors);

        $txn = (new TransactionModel())->findByUuid($body['transaction_uuid']);
        if (!$txn) errorResponse('Transaction not found.', 404);
        if ($txn['status'] !== 'completed') errorResponse('Transaction is not completed.', 400);

        $amount   = (float) $body['amount'];
        $maxRef   = (float) $txn['net_amount'] - (float) $txn['refunded_amount'];
        if ($amount <= 0 || $amount > $maxRef) {
            errorResponse("Max refundable: " . number_format($maxRef, 2), 400);
        }

        try {
            $gateway = ProviderFactory::make($txn['provider']);
            $result  = $gateway->refundPayment($txn['provider_txn_id'], $amount, $body['reason'] ?? 'requested_by_customer');

            $refundModel = new RefundModel();
            $refund = $refundModel->create([
                'transaction_id'     => $txn['id'],
                'user_id'            => $txn['user_id'],
                'processed_by'       => $admin['id'],
                'provider'           => $txn['provider'],
                'provider_refund_id' => $result['provider_refund_id'],
                'amount'             => $amount,
                'currency'           => $txn['currency'],
                'reason'             => $body['reason'] ?? 'requested_by_customer',
                'reason_detail'      => $body['reason_detail'] ?? null,
                'status'             => $result['status'],
            ]);

            $newRefunded = (float) $txn['refunded_amount'] + $amount;
            $newStatus   = $newRefunded >= (float) $txn['net_amount'] ? 'refunded' : 'partially_refunded';
            (new TransactionModel())->update($txn['id'], [
                'refunded_amount' => $newRefunded,
                'status'          => $newStatus,
            ]);

            logPayment('admin.refund', "Admin refund: $amount {$txn['currency']}", 'info',
                ['admin_id' => $admin['id']], $txn['user_id'], $txn['id'], $txn['provider']);

            successResponse($refund, 'Refund initiated.');
        } catch (\Throwable $e) {
            logPayment('admin.refund_error', $e->getMessage(), 'error', ['admin_id' => $admin['id']]);
            errorResponse('Refund failed: ' . $e->getMessage(), 502);
        }
    }

    // ── GET /api/admin/logs ───────────────────────────────────────────────
    public function logs(array $params): void
    {
        $this->admin();
        $page   = max(1, (int) ($_GET['page']  ?? 1));
        $limit  = min(100, max(10, (int) ($_GET['limit'] ?? 50)));
        $offset = ($page - 1) * $limit;
        $level  = $_GET['level'] ?? '';

        $sql  = 'SELECT pl.*, u.email as user_email FROM payment_logs pl LEFT JOIN users u ON u.id=pl.user_id';
        $where = $level ? " WHERE pl.level = " . db()->quote($level) : '';
        $stmt = db()->prepare($sql . $where . ' ORDER BY pl.created_at DESC LIMIT :lim OFFSET :off');
        $stmt->bindValue(':lim', $limit,  \PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $logs = $stmt->fetchAll();

        $total = (int) db()->query("SELECT COUNT(*) FROM payment_logs" . $where)->fetchColumn();
        successResponse(['logs' => $logs, 'total' => $total, 'page' => $page]);
    }

    // ── Plans CRUD ─────────────────────────────────────────────────────────
    public function listPlans(array $params): void
    {
        $this->admin();
        $plans = array_map(fn($p) => (new PlanModel())->decode($p), (new PlanModel())->all(false));
        successResponse($plans);
    }

    public function createPlan(array $params): void
    {
        $this->admin();
        $body   = getRequestBody();
        $errors = validateRequired($body, ['name', 'slug', 'type', 'price_monthly']);
        if (!empty($errors)) errorResponse('Validation failed.', 422, $errors);

        if ((new PlanModel())->findBySlug($body['slug'])) {
            errorResponse('Slug already in use.', 409);
        }

        $plan = (new PlanModel())->create($body);
        successResponse($plan, 'Plan created.', 201);
    }

    public function updatePlan(array $params): void
    {
        $this->admin();
        $body = getRequestBody();
        $plan = (new PlanModel())->findByUuid($params['uuid']);
        if (!$plan) errorResponse('Plan not found.', 404);

        (new PlanModel())->update($params['uuid'], $body);
        successResponse(null, 'Plan updated.');
    }

    public function deletePlan(array $params): void
    {
        $this->admin();
        $plan = (new PlanModel())->findByUuid($params['uuid']);
        if (!$plan) errorResponse('Plan not found.', 404);
        (new PlanModel())->delete($params['uuid']);
        successResponse(null, 'Plan deactivated.');
    }

    // ── Coupons CRUD ───────────────────────────────────────────────────────
    public function listCoupons(array $params): void
    {
        $this->admin();
        $stmt = db()->query('SELECT * FROM coupons ORDER BY created_at DESC');
        successResponse($stmt->fetchAll());
    }

    public function createCoupon(array $params): void
    {
        $admin  = $this->admin();
        $body   = getRequestBody();
        $errors = validateRequired($body, ['code', 'type', 'value']);
        if (!empty($errors)) errorResponse('Validation failed.', 422, $errors);

        $code = strtoupper(trim($body['code']));
        $exists = db()->prepare('SELECT id FROM coupons WHERE code = :c LIMIT 1');
        $exists->execute([':c' => $code]);
        if ($exists->fetch()) errorResponse('Coupon code already exists.', 409);

        $stmt = db()->prepare(
            'INSERT INTO coupons (code, description, type, value, currency, min_amount, max_uses,
             max_uses_per_user, applies_to, plan_ids, valid_from, valid_until, is_active, created_by)
             VALUES (:code,:desc,:type,:val,:cur,:min,:max,:mpu,:at,:pids,:vf,:vu,:active,:cb)'
        );
        $stmt->execute([
            ':code'   => $code,
            ':desc'   => $body['description']       ?? null,
            ':type'   => $body['type'],
            ':val'    => $body['value'],
            ':cur'    => $body['currency']           ?? null,
            ':min'    => $body['min_amount']         ?? 0,
            ':max'    => $body['max_uses']           ?? null,
            ':mpu'    => $body['max_uses_per_user']  ?? 1,
            ':at'     => $body['applies_to']         ?? 'all',
            ':pids'   => isset($body['plan_ids']) ? json_encode($body['plan_ids']) : null,
            ':vf'     => $body['valid_from']         ?? null,
            ':vu'     => $body['valid_until']        ?? null,
            ':active' => 1,
            ':cb'     => $admin['id'],
        ]);

        successResponse(['id' => db()->lastInsertId(), 'code' => $code], 'Coupon created.', 201);
    }

    public function updateCoupon(array $params): void
    {
        $this->admin();
        $body = getRequestBody();
        $allowed = ['description','is_active','valid_until','max_uses'];
        $sets    = [];
        $p       = [':id' => $params['id']];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $body)) { $sets[] = "`$f`=:$f"; $p[":$f"] = $body[$f]; }
        }
        if (empty($sets)) errorResponse('Nothing to update.', 400);
        db()->prepare('UPDATE coupons SET ' . implode(',', $sets) . ' WHERE id=:id')->execute($p);
        successResponse(null, 'Coupon updated.');
    }

    public function deleteCoupon(array $params): void
    {
        $this->admin();
        db()->prepare('UPDATE coupons SET is_active=0 WHERE id=:id')->execute([':id' => $params['id']]);
        successResponse(null, 'Coupon deactivated.');
    }
}
