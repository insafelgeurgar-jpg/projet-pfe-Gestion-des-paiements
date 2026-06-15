<?php
/**
 * PaymentModule - Auth Controller
 * /app/controllers/AuthController.php
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Models\UserModel;
use App\Services\JWTService;
use App\Middleware\RateLimitMiddleware;

class AuthController
{
    private UserModel  $users;
    private JWTService $jwt;

    public function __construct()
    {
        $this->users = new UserModel();
        $this->jwt   = new JWTService();
    }

    // ── POST /api/register ────────────────────────────────────────────────
    public function register(array $params): void
    {
        (new RateLimitMiddleware(10, 60))->handle('register:' . getClientIp());

        $body = getRequestBody();

        $errors = validateRequired($body, ['name', 'email', 'password']);

        if (!empty($body['email']) && !filter_var($body['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Invalid email address.';
        }
        if (!empty($body['password']) && strlen($body['password']) < 8) {
            $errors['password'] = 'Password must be at least 8 characters.';
        }
        if (!empty($errors)) {
            errorResponse('Validation failed.', 422, $errors);
        }

        $email = strtolower(trim($body['email']));

        if ($this->users->findByEmail($email)) {
            errorResponse('This email is already registered.', 409);
        }

        $user = $this->users->create([
            'name'     => sanitize($body['name']),
            'email'    => $email,
            'password' => password_hash($body['password'], PASSWORD_BCRYPT, ['cost' => 12]),
            'role'     => 'user',
            'status'   => 'active',  // Set to 'pending' if email verification required
        ]);

        $token        = $this->jwt->generate(['sub' => $user['id'], 'role' => $user['role']]);
        $refreshToken = $this->issueRefreshToken($user['id']);

        logPayment('register', "User registered: {$email}", 'info', [], $user['id']);

        successResponse([
            'user'          => $this->safeUser($user),
            'access_token'  => $token,
            'refresh_token' => $refreshToken,
            'token_type'    => 'Bearer',
            'expires_in'    => (int) env('JWT_EXPIRY', 3600),
        ], 'Registration successful.', 201);
    }

    // ── POST /api/login ───────────────────────────────────────────────────
    public function login(array $params): void
    {
        (new RateLimitMiddleware(5, 60))->handle('login:' . getClientIp());

        $body   = getRequestBody();
        $errors = validateRequired($body, ['email', 'password']);
        if (!empty($errors)) {
            errorResponse('Validation failed.', 422, $errors);
        }

        $email = strtolower(trim($body['email']));
        $user  = $this->users->findByEmail($email);

        if (!$user || !password_verify($body['password'], $user['password'])) {
            logPayment('login_failed', "Failed login attempt for: $email", 'warning');
            errorResponse('Invalid credentials.', 401);
        }

        if ($user['status'] !== 'active') {
            errorResponse('Your account is ' . $user['status'] . '.', 403);
        }

        // Update last login
        $this->users->updateLastLogin($user['id'], getClientIp());

        $token        = $this->jwt->generate(['sub' => $user['id'], 'role' => $user['role']]);
        $refreshToken = $this->issueRefreshToken($user['id']);

        logPayment('login', "User logged in: $email", 'info', [], $user['id']);

        successResponse([
            'user'          => $this->safeUser($user),
            'access_token'  => $token,
            'refresh_token' => $refreshToken,
            'token_type'    => 'Bearer',
            'expires_in'    => (int) env('JWT_EXPIRY', 3600),
        ], 'Login successful.');
    }

    // ── POST /api/logout ──────────────────────────────────────────────────
    public function logout(array $params): void
    {
        $body = getRequestBody();
        if (!empty($body['refresh_token'])) {
            db()->prepare('UPDATE refresh_tokens SET revoked = 1 WHERE token = :t')
               ->execute([':t' => $body['refresh_token']]);
        }
        successResponse(null, 'Logged out successfully.');
    }

    // ── POST /api/refresh ─────────────────────────────────────────────────
    public function refresh(array $params): void
    {
        $body  = getRequestBody();
        $token = $body['refresh_token'] ?? '';

        if (empty($token)) {
            errorResponse('Refresh token required.', 400);
        }

        $stmt = db()->prepare(
            'SELECT rt.*, u.role, u.status FROM refresh_tokens rt
             JOIN users u ON u.id = rt.user_id
             WHERE rt.token = :t AND rt.revoked = 0 AND rt.expires_at > NOW()
             LIMIT 1'
        );
        $stmt->execute([':t' => $token]);
        $row = $stmt->fetch();

        if (!$row || $row['status'] !== 'active') {
            errorResponse('Invalid or expired refresh token.', 401);
        }

        // Rotate refresh token
        db()->prepare('UPDATE refresh_tokens SET revoked = 1 WHERE token = :t')
            ->execute([':t' => $token]);

        $newAccess  = $this->jwt->generate(['sub' => $row['user_id'], 'role' => $row['role']]);
        $newRefresh = $this->issueRefreshToken($row['user_id']);

        successResponse([
            'access_token'  => $newAccess,
            'refresh_token' => $newRefresh,
            'token_type'    => 'Bearer',
            'expires_in'    => (int) env('JWT_EXPIRY', 3600),
        ]);
    }

    // ── GET /api/me ───────────────────────────────────────────────────────
    public function me(array $params): void
    {
        $user = (new \App\Middleware\AuthMiddleware())->handle();
        successResponse($this->safeUser($user));
    }

    // ── POST /api/password/forgot ─────────────────────────────────────────
    public function forgotPassword(array $params): void
    {
        (new RateLimitMiddleware(3, 300))->handle('forgot:' . getClientIp());
        $body  = getRequestBody();
        $email = strtolower(trim($body['email'] ?? ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            errorResponse('Invalid email address.', 422);
        }

        $user = $this->users->findByEmail($email);
        if ($user) {
            $token   = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', time() + 3600);
            db()->prepare('UPDATE users SET password_reset_token = :t, password_reset_expires = :e WHERE id = :id')
               ->execute([':t' => $token, ':e' => $expires, ':id' => $user['id']]);
            // TODO: Send email with reset link: env('APP_URL') . '/password/reset?token=' . $token
        }

        // Always return success to prevent email enumeration
        successResponse(null, 'If that email exists, a reset link has been sent.');
    }

    // ── POST /api/password/reset ──────────────────────────────────────────
    public function resetPassword(array $params): void
    {
        $body  = getRequestBody();
        $token = $body['token']    ?? '';
        $pass  = $body['password'] ?? '';

        if (empty($token) || strlen($pass) < 8) {
            errorResponse('Token and password (min 8 chars) required.', 422);
        }

        $stmt = db()->prepare(
            'SELECT id FROM users WHERE password_reset_token = :t AND password_reset_expires > NOW() LIMIT 1'
        );
        $stmt->execute([':t' => $token]);
        $user = $stmt->fetch();

        if (!$user) {
            errorResponse('Invalid or expired reset token.', 400);
        }

        db()->prepare(
            'UPDATE users SET password = :p, password_reset_token = NULL, password_reset_expires = NULL WHERE id = :id'
        )->execute([':p' => password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]), ':id' => $user['id']]);

        // Revoke all refresh tokens
        db()->prepare('UPDATE refresh_tokens SET revoked = 1 WHERE user_id = :id')
            ->execute([':id' => $user['id']]);

        successResponse(null, 'Password reset successfully. Please log in.');
    }

    // ── Helpers ───────────────────────────────────────────────────────────
    private function issueRefreshToken(int $userId): string
    {
        $token   = bin2hex(random_bytes(64));
        $expires = date('Y-m-d H:i:s', time() + (int) env('JWT_REFRESH_EXPIRY', 604800));

        db()->prepare(
            'INSERT INTO refresh_tokens (user_id, token, expires_at, ip_address, user_agent)
             VALUES (:uid, :tok, :exp, :ip, :ua)'
        )->execute([
            ':uid' => $userId,
            ':tok' => $token,
            ':exp' => $expires,
            ':ip'  => getClientIp(),
            ':ua'  => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);

        return $token;
    }

    private function safeUser(array $user): array
    {
        unset($user['password'], $user['email_verify_token'], $user['password_reset_token'],
              $user['password_reset_expires'], $user['two_factor_secret']);
        return $user;
    }
}
