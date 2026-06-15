<?php
/**
 * PaymentModule - Checkout Controller
 * /app/controllers/CheckoutController.php
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Middleware\AuthMiddleware;
use App\Middleware\RateLimitMiddleware;
use App\Models\TransactionModel;
use App\Models\PlanModel;
use App\Models\UserModel;
use App\Providers\ProviderFactory;
use App\Services\InvoiceService;
use App\Services\TaxService;
use App\Services\CouponService;

class CheckoutController
{
    // ── POST /api/checkout ────────────────────────────────────────────────
    public function initiate(array $params): void
    {
        (new RateLimitMiddleware(20, 60))->handle();
        $user = (new AuthMiddleware())->handle();
        $body = getRequestBody();

        // Validate
        $errors = validateRequired($body, ['plan_slug', 'provider', 'billing_cycle']);
        if (!empty($errors)) errorResponse('Validation failed.', 422, $errors);

        $provider     = strtolower($body['provider']);
        $billingCycle = strtolower($body['billing_cycle']); // monthly | yearly | once

        if (!in_array($provider, ProviderFactory::supported())) {
            errorResponse("Unsupported payment provider: $provider", 400);
        }

        $planModel = new PlanModel();
        $plan      = $planModel->findBySlug($body['plan_slug']);
        if (!$plan) errorResponse('Plan not found.', 404);

        $plan = $planModel->decode($plan);

        // Calculate amount
        $taxService = new TaxService();
        $couponService = new CouponService();

        $baseAmount = $plan['type'] === 'one_time'
            ? (float) $plan['price_monthly']
            : ($billingCycle === 'yearly' ? (float) $plan['price_yearly'] : (float) $plan['price_monthly']);

        // Apply coupon
        $couponId      = null;
        $discountAmount = 0;
        if (!empty($body['coupon_code'])) {
            $coupon = $couponService->apply($body['coupon_code'], $baseAmount, $user['id'], $plan['id']);
            if ($coupon['valid']) {
                $discountAmount = $coupon['discount'];
                $couponId       = $coupon['coupon_id'];
            }
        }

        $afterDiscount = max(0, $baseAmount - $discountAmount);
        $taxAmount     = $taxService->calculate($afterDiscount, $user);
        $totalAmount   = $afterDiscount + $taxAmount;
        $currency      = $body['currency'] ?? $plan['currency'] ?? env('DEFAULT_CURRENCY', 'USD');

        // Get user full record for provider
        $userFull = (new UserModel())->findById($user['id']);

        $paymentData = [
            'user_id'           => $user['id'],
            'plan_id'           => $plan['id'],
            'email'             => $userFull['email'],
            'name'              => $userFull['name'],
            'amount'            => $totalAmount,
            'currency'          => $currency,
            'description'       => $plan['name'] . ' - ' . ucfirst($billingCycle),
            'billing_cycle'     => $billingCycle,
            'coupon_id'         => $couponId,
            'stripe_customer_id'=> $userFull['stripe_customer_id'],
        ];

        // Create transaction record (pending)
        $txnModel = new TransactionModel();
        $txn = $txnModel->create([
            'user_id'         => $user['id'],
            'plan_id'         => $plan['id'],
            'coupon_id'       => $couponId,
            'provider'        => $provider,
            'type'            => $plan['type'] === 'subscription' ? 'subscription' : 'payment',
            'status'          => 'pending',
            'amount'          => $totalAmount,
            'tax_amount'      => $taxAmount,
            'discount_amount' => $discountAmount,
            'net_amount'      => $totalAmount,
            'currency'        => $currency,
            'description'     => $paymentData['description'],
            'billing_name'    => $userFull['name'],
            'billing_email'   => $userFull['email'],
        ]);

        try {
            $gateway = ProviderFactory::make($provider);

            $result = $plan['type'] === 'subscription'
                ? $this->initiateSubscription($gateway, $plan, $paymentData, $billingCycle)
                : $gateway->createPayment($paymentData);

            // Update transaction with provider ID
            $txnModel->update($txn['id'], [
                'provider_txn_id'          => $result['provider_txn_id'] ?? null,
                'provider_payment_intent'  => $result['client_secret'] ?? null,
            ]);

            // Update Stripe customer ID if returned
            if (!empty($result['stripe_customer_id']) && empty($userFull['stripe_customer_id'])) {
                (new UserModel())->update($user['id'], ['stripe_customer_id' => $result['stripe_customer_id']]);
            }

            logPayment('checkout.initiated', "Checkout initiated via $provider", 'info',
                ['txn_id' => $txn['id'], 'provider' => $provider], $user['id'], $txn['id'], $provider);

            successResponse([
                'transaction_uuid'  => $txn['uuid'],
                'provider'          => $provider,
                'amount'            => $totalAmount,
                'tax_amount'        => $taxAmount,
                'discount_amount'   => $discountAmount,
                'currency'          => $currency,
                'client_secret'     => $result['client_secret']     ?? null,
                'redirect_url'      => $result['redirect_url']      ?? null,
                'form_fields'       => $result['form_fields']       ?? null,
                'provider_txn_id'   => $result['provider_txn_id']   ?? null,
                'method'            => $result['method']            ?? 'redirect',
            ], 'Checkout initiated.');

        } catch (\Throwable $e) {
            $txnModel->update($txn['id'], [
                'status'          => 'failed',
                'failure_message' => $e->getMessage(),
            ]);
            logPayment('checkout.error', $e->getMessage(), 'error', [], $user['id'], $txn['id'], $provider);
            errorResponse('Payment initiation failed: ' . $e->getMessage(), 502);
        }
    }

    // ── POST /api/checkout/verify ──────────────────────────────────────────
    public function verify(array $params): void
    {
        (new AuthMiddleware())->handle();
        $body = getRequestBody();

        $errors = validateRequired($body, ['transaction_uuid', 'provider_txn_id']);
        if (!empty($errors)) errorResponse('Validation failed.', 422, $errors);

        $txnModel = new TransactionModel();
        $txn      = $txnModel->findByUuid($body['transaction_uuid']);
        if (!$txn) errorResponse('Transaction not found.', 404);

        try {
            $gateway = ProviderFactory::make($txn['provider']);
            $result  = $gateway->verifyPayment($body['provider_txn_id']);

            $txnModel->update($txn['id'], [
                'status'               => $result['status'],
                'provider_txn_id'      => $result['provider_txn_id'],
                'payment_method'       => $result['payment_method']       ?? null,
                'payment_method_last4' => $result['payment_method_last4'] ?? null,
            ]);

            // Generate invoice on success
            if ($result['status'] === 'completed') {
                $updatedTxn = $txnModel->findById($txn['id']);
                (new InvoiceService())->generateForTransaction($updatedTxn);
                logPayment('payment.completed', 'Payment verified', 'info', [], $txn['user_id'], $txn['id']);
            }

            successResponse([
                'status'           => $result['status'],
                'provider_txn_id'  => $result['provider_txn_id'],
            ], 'Payment ' . $result['status'] . '.');

        } catch (\Throwable $e) {
            logPayment('verify.error', $e->getMessage(), 'error', [], $txn['user_id'] ?? null, $txn['id'] ?? null);
            errorResponse('Verification failed: ' . $e->getMessage(), 502);
        }
    }

    // ── GET /api/checkout/session/:id ─────────────────────────────────────
    public function session(array $params): void
    {
        $user = (new AuthMiddleware())->handle();
        $txn  = (new TransactionModel())->findByUuid($params['id']);

        if (!$txn || (int) $txn['user_id'] !== (int) $user['id']) {
            errorResponse('Session not found.', 404);
        }

        successResponse([
            'uuid'     => $txn['uuid'],
            'status'   => $txn['status'],
            'amount'   => $txn['amount'],
            'currency' => $txn['currency'],
            'provider' => $txn['provider'],
        ]);
    }

    private function initiateSubscription($gateway, array $plan, array $data, string $billingCycle): array
    {
        $priceId = $billingCycle === 'yearly'
            ? $plan['stripe_yearly_price_id']
            : $plan['stripe_monthly_price_id'];

        if (empty($priceId) && $data['provider'] !== 'stripe') {
            // Fall through to one-time payment for non-Stripe providers
            return $gateway->createPayment($data);
        }

        return $gateway->createSubscription([...$data, 'stripe_price_id' => $priceId,
            'trial_days' => $plan['trial_days']]);
    }
}
