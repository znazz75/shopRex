<?php

namespace ShopRex\Controllers\Admin;

use ShopRex\Core\Auth\AdminAuth;
use ShopRex\Core\Container;
use ShopRex\Core\Controller;
use ShopRex\Core\Request;
use ShopRex\Core\Response;
use ShopRex\Services\I18n;
use ShopRex\Services\RateLimiter;

/**
 * Direct port of admin/login.php + admin/logout.php. Extends the plain
 * Controller, not AdminController - AdminController::__construct() always
 * calls AdminAuth::requireLogin(), which would make the login page itself
 * unreachable (redirect to itself forever) for a visitor who isn't logged
 * in yet.
 *
 * Shares the exact same RateLimiter binding (the login_attempts table) as
 * Controllers\Storefront\AuthController's customer login - the legacy app
 * only ever had one login_attempts table/isRateLimited() mechanism, used
 * by both login.php and admin/login.php identically.
 */
final class AdminAuthController extends Controller
{
    private readonly \PDO $pdo;
    private readonly RateLimiter $rateLimiter;

    public function __construct(Request $request, Container $container)
    {
        parent::__construct($request, $container);
        $this->pdo = $container->make(\PDO::class);
        $this->rateLimiter = $container->make(RateLimiter::class);
    }

    public function showLogin(Request $request): Response
    {
        if (AdminAuth::current()) {
            return $this->redirect('/admin');
        }
        return $this->renderStandalone('auth/login', ['error' => null, 'pageTitle' => __('admin.sign_in')]);
    }

    public function login(Request $request): Response
    {
        if (AdminAuth::current()) {
            return $this->redirect('/admin');
        }
        if ($csrfFailure = $this->requireCsrf()) {
            return $csrfFailure;
        }

        $username = trim((string)$request->post('username', ''));
        $password = (string)$request->post('password', '');
        $rateLimitId = $this->rateLimiter->identifierFor($username);

        $error = null;
        if ($this->rateLimiter->tooManyAttempts($rateLimitId)) {
            $error = __('auth.too_many_attempts');
        } else {
            $stmt = $this->pdo->prepare("SELECT * FROM admin_users WHERE username = ? AND status = 'active'");
            $stmt->execute([$username]);
            $admin = $stmt->fetch();

            if ($admin && password_verify($password, $admin['password_hash'])) {
                $this->rateLimiter->clearAttempts($rateLimitId);
                $this->request->session()->regenerate();
                $this->csrf->rotate();
                $this->request->session()->set('admin_id', (int)$admin['id']);
                $this->pdo->prepare('UPDATE admin_users SET last_login = NOW() WHERE id = ?')->execute([$admin['id']]);
                AdminAuth::forget();
                return $this->redirect('/admin');
            }
            $this->rateLimiter->recordFailedAttempt($rateLimitId);
            $error = __('auth.invalid_credentials');
        }

        return $this->renderStandalone('auth/login', ['error' => $error, 'pageTitle' => __('admin.sign_in')]);
    }

    public function logout(Request $request): Response
    {
        $this->request->session()->remove('admin_id');
        AdminAuth::forget();
        return $this->redirect('/admin/login');
    }
}
