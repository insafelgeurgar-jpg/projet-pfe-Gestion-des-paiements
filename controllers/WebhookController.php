<?php
/**
 * PaymentModule - Webhook Controller
 * /app/controllers/WebhookController.php
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Providers\ProviderFactory;
use App\Models\WebhookModel;
use App\Models\TransactionModel;
use App\Models\SubscriptionModel;
use App\Services\InvoiceService;

class WebhookController
{
    // ── POST /api/webhook/stripe ──────────────────────────────────────────
    public function stripe(array $params): void
    {
        $this->process('stripe');
    }

    // ── POST /api/webhook/paypal ──────────────────────────────────────────
    public function paypal(array $params): void
    {
        $this->process('paypal');
    }

    // ── POST /api/webhook/paymob ──────────────────────────────────────────
    public function paymob(array $params): void
    {
        $this->process('paymob');
    }

    // ── POST /api/webhook/checkout ────────────────────────────────────────
    public function checkout(array $params): void
    {
        $this->process('checkout');
    }

    // ── Internal dispatcher ───────────────────────────────────────────────
    private function process(string $provider): void
    {
        $rawPayload = file_get_contents('php://input');
        $headers    = getallheaders() ?: [];
        $webhookModel = new WebhookModel();

        // Store raw webhook immediately
        $webhookId = $webhookModel->store([
            'provider'   => $provider,
            'payload'    => $rawPayload,
            'headers'    => json_encode($headers),
            'ip_address' => getClientIp(),
            'status'     => 'received',
        ]);

        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode(['received' => true]);

        // Process synchronously (for production use a queue)
        try {
            $gateway = ProviderFactory::make($provider);
            $event   = $gateway->handleWebhook($rawPayload, $headers);

            $webhookModel->update($webhookId, ['status' => 'processing', 'verified' => 1, 'event_type' => $event['event']]);

            $this->handleEvent($provider, $event['event'], $event['data']);

            $webhookModel->update($webhookId, [
                'status'       => 'processed',
                'processed_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            $webhookModel->update($webhookId, [
                'status'   => 'failed',
                'error'    => $e->getMessage(),
                'attempts' => 1,
            ]);
            logPayment('webhook.error', $e->getMessage(), 'error', ['provider' => $provider]);
        }
        exit;
    }

    private function handleEvent(string $provider, string $eventType, array $data): void
    {
        $txnModel  = new TransactionModel();
        $subModel  = new SubscriptionModel();

        switch ($eventType) {
            // ── Stripe events ─────────────────────────────────────────────
            case 'payment_intent.succeeded':
                $txn = $txnModel->findByProviderTxnId($data['id'], 'stripe');
                if ($txn) {
                    $txnModel->update($txn['id'], ['status' => 'completed']);
                    (new InvoiceService())->generateForTransaction($txnModel->findById($txn['id']));
                }
                break;

            case 'payment_intent.payment_failed':
                $txn = $txnModel->findByProviderTxnId($data['id'], 'stripe');
                if ($txn) {
                    $txnModel->update($txn['id'], [
                        'status'          => 'failed',
                        'failure_code'    => $data['last_payment_error']['code'] ?? null,
                        'failure_message' => $data['last_payment_error']['message'] ?? null,
                    ]);
                }
                break;

            case 'customer.subscription.updated':
                $sub = $subModel->findByProviderSubId($data['id']);
                if ($sub) {
                    $subModel->updateStatus($sub['id'], $this->mapStripeSubStatus($data['status']));
                }
                break;

            case 'customer.subscription.deleted':
                $sub = $subModel->findByProviderSubId($data['id']);
                if ($sub) {
                    db()->prepare(
                        "UPDATE subscriptions SET status = 'expired', ended_at = NOW() WHERE id = :id"
                    )->execute([':id' => $sub['id']]);
                }
                break;

            case 'invoice.payment_succeeded':
                // Subscription renewal - create transaction
                $sub = $subModel->findByProviderSubId($data['subscription'] ?? '');
                if ($sub) {
                    $amount = amountFromCents($data['amount_paid'] ?? 0);
                    $txn = $txnModel->create([
                        'user_id'         => $sub['user_id'],
                        'subscription_id' => $sub['id'],
                        'plan_id'         => $sub['plan_id'],
                        'provider'        => 'stripe',
                        'provider_txn_id' => $data['payment_intent'] ?? null,
                        'type'            => 'renewal',
                        'status'          => 'completed',
                        'amount'          => $amount,
                        'net_amount'      => $amount,
                        'currency'        => strtoupper($data['currency'] ?? 'USD'),
                        'description'     => 'Subscription renewal',
                    ]);
                    (new InvoiceService())->generateForTransaction($txn);

                    // Extend period
                    db()->prepare(
                        'UPDATE subscriptions SET current_period_start = :s, current_period_end = :e WHERE id = :id'
                    )->execute([
                        ':s'  => date('Y-m-d H:i:s', $data['period_start']),
                        ':e'  => date('Y-m-d H:i:s', $data['period_end']),
                        ':id' => $sub['id'],
                    ]);
                }
                break;

            case 'charge.refunded':
                $txn = $txnModel->findByProviderTxnId($data['payment_intent'] ?? '', 'stripe');
                if ($txn) {
                    $refundedAmount = amountFromCents($data['amount_refunded'] ?? 0);
                    $newStatus      = $refundedAmount >= (float) $txn['net_amount'] ? 'refunded' : 'partially_refunded';
                    $txnModel->update($txn['id'], ['refunded_amount' => $refundedAmount, 'status' => $newStatus]);
                }
                break;

            // ── PayPal events ─────────────────────────────────────────────
            case 'PAYMENT.CAPTURE.COMPLETED':
                $txn = $txnModel->findByProviderTxnId($data['id'] ?? '', 'paypal');
                if ($txn) {
                    $txnModel->update($txn['id'], ['status' => 'completed']);
                    (new InvoiceService())->generateForTransaction($txnModel->findById($txn['id']));
                }
                break;

            case 'PAYMENT.CAPTURE.DENIED':
            case 'PAYMENT.CAPTURE.DECLINED':
                $txn = $txnModel->findByProviderTxnId($data['id'] ?? '', 'paypal');
                if ($txn) $txnModel->update($txn['id'], ['status' => 'failed']);
                break;

            case 'BILLING.SUBSCRIPTION.CANCELLED':
            case 'BILLING.SUBSCRIPTION.EXPIRED':
                $sub = $subModel->findByProviderSubId($data['id'] ?? '');
                if ($sub) {
                    db()->prepare(
                        "UPDATE subscriptions SET status = 'cancelled', ended_at = NOW(), cancelled_at = NOW() WHERE id = :id"
                    )->execute([':id' => $sub['id']]);
                }
                break;

            // ── Paymob / Checkout generic events ──────────────────────────
            case 'transaction.success':
            case 'payment.completed':
                $providerTxnId = $data['id'] ?? $data['payment_id'] ?? '';
                $txn = $txnModel->findByProviderTxnId((string)$providerTxnId, $provider ?? 'paymob');
                if ($txn) {
                    $txnModel->update($txn['id'], ['status' => 'completed']);
                    (new InvoiceService())->generateForTransaction($txnModel->findById($txn['id']));
                }
                break;

            case 'transaction.failed':
            case 'payment.failed':
                $providerTxnId = $data['id'] ?? $data['payment_id'] ?? '';
                $txn = $txnModel->findByProviderTxnId((string)$providerTxnId, $provider ?? 'paymob');
                if ($txn) $txnModel->update($txn['id'], ['status' => 'failed']);
                break;

            default:
                logPayment('webhook.ignored', "Unhandled event: $eventType ($provider)", 'debug');
        }
    }

    private function mapStripeSubStatus(string $s): string
    {
        return match ($s) {
            'active'   => 'active',
            'trialing' => 'trialing',
            'past_due' => 'past_due',
            'canceled' => 'cancelled',
            'unpaid'   => 'past_due',
            default    => 'active',
        };
    }
}
