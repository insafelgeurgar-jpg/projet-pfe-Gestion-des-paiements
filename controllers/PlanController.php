<?php
/**
 * PaymentModule - Plan Controller
 * /app/controllers/PlanController.php
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Models\PlanModel;

class PlanController
{
    private PlanModel $plans;

    public function __construct()
    {
        $this->plans = new PlanModel();
    }

    // ── GET /api/plans ────────────────────────────────────────────────────
    public function index(array $params): void
    {
        $plans = array_map(
            fn($p) => $this->formatPlan($p),
            $this->plans->all(activeOnly: true)
        );
        successResponse($plans);
    }

    // ── GET /api/plans/:slug ──────────────────────────────────────────────
    public function show(array $params): void
    {
        $plan = $this->plans->findBySlug($params['slug']);
        if (!$plan) errorResponse('Plan not found.', 404);
        successResponse($this->formatPlan($plan));
    }

    private function formatPlan(array $plan): array
    {
        $plan = (new PlanModel())->decode($plan);
        unset(
            $plan['stripe_monthly_price_id'],
            $plan['stripe_yearly_price_id'],
            $plan['paypal_monthly_plan_id'],
            $plan['paypal_yearly_plan_id']
        );
        return $plan;
    }
}
