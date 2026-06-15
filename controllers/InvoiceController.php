<?php
/**
 * PaymentModule - Invoice Controller
 * /app/controllers/InvoiceController.php
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Middleware\AuthMiddleware;
use App\Models\InvoiceModel;
use App\Services\InvoiceService;

class InvoiceController
{
    // ── GET /api/invoices ─────────────────────────────────────────────────
    public function index(array $params): void
    {
        $user    = (new AuthMiddleware())->handle();
        $page    = max(1, (int) ($_GET['page']  ?? 1));
        $limit   = min(50, max(10, (int) ($_GET['limit'] ?? 20)));
        $offset  = ($page - 1) * $limit;

        $invoices = (new InvoiceModel())->listForUser($user['id'], $limit, $offset);
        successResponse(['invoices' => $invoices, 'page' => $page, 'limit' => $limit]);
    }

    // ── GET /api/invoices/:uuid ───────────────────────────────────────────
    public function show(array $params): void
    {
        $user    = (new AuthMiddleware())->handle();
        $invoice = (new InvoiceModel())->findByUuid($params['uuid']);

        if (!$invoice || (int) $invoice['user_id'] !== (int) $user['id']) {
            errorResponse('Invoice not found.', 404);
        }
        successResponse($invoice);
    }

    // ── GET /api/invoices/:uuid/pdf ───────────────────────────────────────
    public function pdf(array $params): void
    {
        $user    = (new AuthMiddleware())->handle();
        $invoice = (new InvoiceModel())->findByUuid($params['uuid']);

        if (!$invoice || (int) $invoice['user_id'] !== (int) $user['id']) {
            errorResponse('Invoice not found.', 404);
        }

        $pdfPath = (new InvoiceService())->getPdfPath($invoice);
        if (!file_exists($pdfPath)) {
            errorResponse('PDF not available yet.', 404);
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="invoice-' . $invoice['number'] . '.pdf"');
        header('Content-Length: ' . filesize($pdfPath));
        readfile($pdfPath);
        exit;
    }
}
