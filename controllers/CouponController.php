<?php
/**
 * PaymentModule - Coupon Controller
 * /app/controllers/CouponController.php
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Middleware\AuthMiddleware;
use App\Middleware\RateLimitMiddleware;
use App\Services\CouponService;

class CouponController
{
    // ── POST /api/coupon/apply ────────────────────────────────────────────
    public function apply(array $params): void
    {
        (new RateLimitMiddleware(10, 60))->handle();
        $user = (new AuthMiddleware())->handle();
        $body = getRequestBody();

        $errors = validateRequired($body, ['code', 'amount']);
        if (!empty($errors)) errorResponse('Validation failed.', 422, $errors);

        $result = (new CouponService())->apply(
            strtoupper(trim($body['code'])),
            (float) $body['amount'],
            $user['id'],
            (int) ($body['plan_id'] ?? 0)
        );

        if (!$result['valid']) {
            errorResponse($result['message'], 422);
        }

        successResponse([
            'coupon_id'       => $result['coupon_id'],
            'code'            => $result['code'],
            'type'            => $result['type'],
            'discount'        => $result['discount'],
            'final_amount'    => $result['final_amount'],
            'description'     => $result['description'],
        ], 'Coupon applied.');
    }
}
