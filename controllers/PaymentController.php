<?php
/**
 * PaymentModule - Payment Controller
 * /app/controllers/PaymentController.php
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Middleware\AuthMiddleware;
use App\Middleware\RateLimitMiddleware;
use App\Models\TransactionModel;
use App\Models\RefundModel;
use App\Providers\ProviderFactory;

class PaymentController
{
    // ── GET /api/payments ─────────────────────────────────────────────────
    public function index(array $params): void
    {
        $user   = (new AuthMiddleware())->handle();
        $page   = max(1, (int) ($_GET['page']  ?? 1));
        $limit  = min(50, max(10, (int) ($_GET['limit'] ?? 20)));

        $offset = ($page - 1) * $limit;

        $txns = (new TransactionModel())->listForUser($user['id'], $limit, $offset);
        successResponse(['transactions' => $txns, 'page' => $page, 'limit' => $limit]);
    }

    // ── GET /api/payments/:uuid ───────────────────────────────────────────
    public function show(array $params): void
    {
        $user = (new AuthMiddleware())->handle();
        $txn  = (new TransactionModel())->findByUuid($params['uuid']);

        if (!$txn || (int) $txn['user_id'] !== (int) $user['id']) {
            errorResponse('Transaction not found.', 404);
        }
        successResponse($txn);
    }

    // ── POST /api/payments/:uuid/refund ────────────────────────────────────
    public function refund(array $params): void
    {
        (new RateLimitMiddleware(5, 60))->handle();
        $user = (new AuthMiddleware())->handle();
        $body = getRequestBody();
        $txn  = (new TransactionModel())->findByUuid($params['uuid']);

        if (!$txn || (int) $txn['user_id'] !== (int) $user['id']) {
            errorResponse('Transaction not found.', 404);
        }
        if ($txn['status'] !== 'completed') {
            errorResponse('Only completed transactions can be refunded.', 400);
        }

        $refundAmount = (float) ($body['amount'] ?? $txn['net_amount']);
        $maxRefund    = (float) $txn['net_amount'] - (float) $txn['refunded_amount'];

        if ($refundAmount <= 0 || $refundAmount > $maxRefund) {
            errorResponse("Refund amount must be between 0.01 and " . number_format($maxRefund, 2), 400);
        }

        try {
            $gateway = ProviderFactory::make($txn['provider']);
            $result  = $gateway->refundPayment(
                $txn['provider_txn_id'],
                $refundAmount,
                $body['reason'] ?? 'requested_by_customer'
            );

            // Record refund
            $refundModel = new RefundModel();
            $refund = $refundModel->create([
                'transaction_id'     => $txn['id'],
                'user_id'            => $user['id'],
                'processed_by'       => $user['id'],
                'provider'           => $txn['provider'],
                'provider_refund_id' => $result['provider_refund_id'],
                'amount'             => $refundAmount,
                'currency'           => $txn['currency'],
                'reason'             => $body['reason'] ?? 'requested_by_customer',
                'status'             => $result['status'],
            ]);

            $txnModel    = new TransactionModel();
            $newRefunded = (float) $txn['refunded_amount'] + $refundAmount;
            $newStatus   = $newRefunded >= (float) $txn['net_amount'] ? 'refunded' : 'partially_refunded';

            $txnModel->update($txn['id'], [
                'refunded_amount' => $newRefunded,
                'status'          => $newStatus,
            ]);

            logPayment('refund.created', "Refund of $refundAmount {$txn['currency']}", 'info',
                ['refund_id' => $refund['id']], $user['id'], $txn['id'], $txn['provider']);

            successResponse($refund, 'Refund initiated.');
        } catch (\Throwable $e) {
            logPayment('refund.error', $e->getMessage(), 'error', [], $user['id'], $txn['id']);
            errorResponse('Refund failed: ' . $e->getMessage(), 502);
        }
    }
}
