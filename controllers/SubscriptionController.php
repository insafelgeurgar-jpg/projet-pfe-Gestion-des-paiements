<?php
/**
 * PaymentModule - Subscription Controller
 * /app/controllers/SubscriptionController.php
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Middleware\AuthMiddleware;
use App\Models\SubscriptionModel;
use App\Providers\ProviderFactory;

class SubscriptionController
{
    private SubscriptionModel $subs;

    public function __construct()
    {
        $this->subs = new SubscriptionModel();
    }

    // ── GET /api/subscription ─────────────────────────────────────────────
    public function current(array $params): void
    {
        $user = (new AuthMiddleware())->handle();
        $sub  = $this->subs->activeForUser($user['id']);
        successResponse($sub);
    }

    // ── GET /api/subscription/history ────────────────────────────────────
    public function history(array $params): void
    {
        $user = (new AuthMiddleware())->handle();
        $subs = $this->subs->listForUser($user['id']);
        successResponse($subs);
    }

    // ── POST /api/subscription/create ────────────────────────────────────
    public function create(array $params): void
    {
        $user = (new AuthMiddleware())->handle();
        $body = getRequestBody();

        $errors = validateRequired($body, ['plan_id', 'provider', 'billing_cycle']);
        if (!empty($errors)) errorResponse('Validation failed.', 422, $errors);

        // Check no active sub exists
        $existing = $this->subs->activeForUser($user['id']);
        if ($existing) errorResponse('You already have an active subscription.', 409);

        $sub = $this->subs->create([
            'user_id'              => $user['id'],
            'plan_id'              => (int) $body['plan_id'],
            'provider'             => $body['provider'],
            'provider_sub_id'      => $body['provider_sub_id'] ?? null,
            'billing_cycle'        => $body['billing_cycle'],
            'status'               => 'active',
            'current_period_start' => date('Y-m-d H:i:s'),
            'current_period_end'   => $body['billing_cycle'] === 'yearly'
                ? date('Y-m-d H:i:s', strtotime('+1 year'))
                : date('Y-m-d H:i:s', strtotime('+1 month')),
        ]);

        successResponse($sub, 'Subscription created.', 201);
    }

    // ── POST /api/subscription/cancel ────────────────────────────────────
    public function cancel(array $params): void
    {
        $user = (new AuthMiddleware())->handle();
        $body = getRequestBody();

        $sub = $this->subs->activeForUser($user['id']);
        if (!$sub) errorResponse('No active subscription found.', 404);

        $immediately = (bool) ($body['immediately'] ?? false);

        // Cancel via provider if has provider ID
        if (!empty($sub['provider_sub_id']) && $sub['provider'] !== 'manual') {
            try {
                $gateway = ProviderFactory::make($sub['provider']);
                $gateway->cancelSubscription($sub['provider_sub_id'], $immediately);
            } catch (\Throwable $e) {
                logPayment('subscription.cancel_error', $e->getMessage(), 'warning', [], $user['id']);
            }
        }

        $this->subs->cancel($sub['id'], $immediately);

        logPayment('subscription.cancelled', 'Subscription cancelled', 'info',
            ['sub_id' => $sub['id'], 'immediately' => $immediately], $user['id']);

        successResponse(null, $immediately
            ? 'Subscription cancelled immediately.'
            : 'Subscription will cancel at end of billing period.');
    }

    // ── POST /api/subscription/resume ─────────────────────────────────────
    public function resume(array $params): void
    {
        $user = (new AuthMiddleware())->handle();
        $sub  = $this->subs->findCancelledForUser($user['id']);

        if (!$sub || $sub['cancel_at_period_end'] !== '1') {
            errorResponse('No cancellable subscription found.', 404);
        }

        // Resume via provider
        if (!empty($sub['provider_sub_id']) && $sub['provider'] === 'stripe') {
            try {
                $ch = curl_init("https://api.stripe.com/v1/subscriptions/{$sub['provider_sub_id']}");
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => 'cancel_at_period_end=false',
                    CURLOPT_USERPWD        => env('STRIPE_SECRET_KEY') . ':',
                    CURLOPT_TIMEOUT        => 30,
                ]);
                curl_exec($ch);
                curl_close($ch);
            } catch (\Throwable) {}
        }

        $this->subs->resume($sub['id']);
        successResponse(null, 'Subscription resumed.');
    }
}
