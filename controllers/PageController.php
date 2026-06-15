<?php
/**
 * PaymentModule - Page Controller (serves HTML views)
 * /app/controllers/PageController.php
 */

declare(strict_types=1);

namespace App\Controllers;

class PageController
{
    private function render(string $view, array $vars = []): void
    {
        extract($vars);
        $csrfToken = csrfToken();
        $appName   = env('APP_NAME', 'PaymentModule');
        $appUrl    = env('APP_URL', '');
        $stripeKey = env('STRIPE_PUBLIC_KEY', '');

        $viewFile = BASE_PATH . "/views/{$view}.php";
        if (!file_exists($viewFile)) {
            http_response_code(404);
            echo "View not found.";
            exit;
        }
        require $viewFile;
        exit;
    }

    public function pricing(array $p):       void { $this->render('pricing'); }
    public function checkout(array $p):      void { $this->render('checkout'); }
    public function login(array $p):         void { $this->render('auth/login'); }
    public function register(array $p):      void { $this->render('auth/register'); }
    public function dashboard(array $p):     void { $this->render('user/dashboard'); }
    public function adminDashboard(array $p):void { $this->render('admin/dashboard'); }
    public function paymentSuccess(array $p):void { $this->render('payment-success'); }
    public function paymentFailed(array $p): void { $this->render('payment-failed'); }
}
