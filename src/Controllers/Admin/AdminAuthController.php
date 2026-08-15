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
    // Shared PDO connection, used directly here (not via a Model) to look up
    // and update the admin_users row by hand during login.
    private readonly \PDO $pdo;
    // Shared brute-force guard - counts failed attempts per identifier and
    // temporarily blocks further tries once a threshold is hit.
    private readonly RateLimiter $rateLimiter;

    public function __construct(Request $request, Container $container)
    {
        parent::__construct($request, $container);
        $this->pdo = $container->make(\PDO::class);
        $this->rateLimiter = $container->make(RateLimiter::class);
    }

    /** Shows the admin login form, or bounces an already-logged-in admin straight to the dashboard instead of showing them a login page they don't need. */
    public function showLogin(Request $request): Response
    {
        if (AdminAuth::current()) {
            return $this->redirect('/admin');
        }
        return $this->renderStandalone('auth/login', ['error' => null, 'pageTitle' => __('admin.sign_in')]);
    }

    /** Validates the submitted username/password, applying rate limiting and CSRF protection, and starts a fresh authenticated session on success. */
    public function login(Request $request): Response
    {
        if (AdminAuth::current()) {
            return $this->redirect('/admin');
        }
        // Blocks a forged login submission from a page the admin didn't intend
        // to submit (CSRF) - see Controller::requireCsrf().
        if ($csrfFailure = $this->requireCsrf()) {
            return $csrfFailure;
        }

        $username = trim((string)$request->post('username', ''));
        $password = (string)$request->post('password', '');
        // Rate limiting is keyed by the submitted username (not IP alone) so
        // repeated guesses against one account get throttled even from
        // different IPs/proxies - see RateLimiter::identifierFor().
        $rateLimitId = $this->rateLimiter->identifierFor($username);

        $error = null;
        if ($this->rateLimiter->tooManyAttempts($rateLimitId)) {
            // Too many recent failed attempts for this identifier - refuse to even
            // check the password, to slow down brute-force/credential-stuffing
            // attempts.
            $error = __('auth.too_many_attempts');
        } else {
            // status = 'active' excludes disabled admin accounts from logging in
            // at all, even with a correct password.
            $stmt = $this->pdo->prepare("SELECT * FROM admin_users WHERE username = ? AND status = 'active'");
            $stmt->execute([$username]);
            $admin = $stmt->fetch();

            // password_verify() compares the submitted password against the
            // stored bcrypt hash - never a plain-text comparison, and timing-safe.
            if ($admin && password_verify($password, $admin['password_hash'])) {
                $this->rateLimiter->clearAttempts($rateLimitId);
                // Session-fixation defense: issue a brand new session id and CSRF
                // token on every successful login, so a session id an attacker
                // knew about beforehand (e.g. planted via a shared/public
                // computer) can't be reused to hijack this now-authenticated
                // session - see CLAUDE.md's Security posture section.
                $this->request->session()->regenerate();
                $this->csrf->rotate();
                $this->request->session()->set('admin_id', (int)$admin['id']);
                $this->pdo->prepare('UPDATE admin_users SET last_login = NOW() WHERE id = ?')->execute([$admin['id']]);
                // Clears AdminAuth's per-request memoized "current admin" cache so
                // the very next AdminAuth::current() call re-reads from the DB
                // instead of returning a stale "not logged in" result from earlier
                // in this same request.
                AdminAuth::forget();
                return $this->redirect('/admin');
            }
            $this->rateLimiter->recordFailedAttempt($rateLimitId);
            // Deliberately the same generic error whether the username didn't
            // exist, the account is inactive, or the password was wrong - avoids
            // telling an attacker which part was correct.
            $error = __('auth.invalid_credentials');
        }

        return $this->renderStandalone('auth/login', ['error' => $error, 'pageTitle' => __('admin.sign_in')]);
    }

    /** Ends the admin's session and sends them back to the login page. */
    public function logout(Request $request): Response
    {
        $this->request->session()->remove('admin_id');
        // Clears the memoized "current admin" cache so any later AdminAuth::current()
        // call in this same request correctly reports "logged out" instead of
        // returning the value it cached before the session was cleared.
        AdminAuth::forget();
        return $this->redirect('/admin/login');
    }
}
