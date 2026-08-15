<?php

namespace ShopRex\Controllers\Storefront;

use ShopRex\Core\Auth\CustomerAuth;
use ShopRex\Core\Container;
use ShopRex\Core\Controller;
use ShopRex\Core\Request;
use ShopRex\Core\Response;
use ShopRex\Services\I18n;
use ShopRex\Services\RateLimiter;

/**
 * Everything a storefront visitor needs to manage their own customer
 * account credentials: sign in, register, sign out, and the forgot/reset
 * password flow. Kept as one controller (rather than splitting login vs.
 * registration vs. password reset into separate classes) because they're
 * all small, closely related "authentication" actions sharing the same
 * rate-limiter and CSRF/session patterns. Direct port of login.php +
 * register.php + logout.php + forgot_password.php + reset_password.php.
 */
final class AuthController extends Controller
{
    private readonly \PDO $pdo; // Raw DB handle for the hand-written customer lookup/update queries below.
    private readonly RateLimiter $rateLimiter; // Throttles login attempts and password-reset requests per email/IP to slow down brute-force guessing (docs/SECURITY_AUDIT.md finding #5).

    public function __construct(Request $request, Container $container)
    {
        parent::__construct($request, $container);
        $this->pdo = $container->make(\PDO::class);
        $this->rateLimiter = $container->make(RateLimiter::class);
    }

    /** Shows the login form - redirects away if the visitor is already signed in, since there's nothing to log in to. */
    public function showLogin(Request $request): Response
    {
        if (CustomerAuth::check()) {
            return $this->redirect('/account');
        }
        return $this->render('auth/login', ['error' => null, 'pageTitle' => __('auth.sign_in')]);
    }

    /**
     * Verifies the submitted email/password against the customers table
     * and establishes a session on success. Rate-limited per email to
     * blunt brute-force password guessing, and takes the usual
     * session-fixation defenses (regenerating the session id and rotating
     * the CSRF token) right at the moment of privilege escalation - see
     * CLAUDE.md's "Security posture" section.
     */
    public function login(Request $request): Response
    {
        if (CustomerAuth::check()) {
            return $this->redirect('/account');
        }
        if ($csrfFailure = $this->requireCsrf()) {
            return $csrfFailure;
        }

        $email = trim((string)$request->post('email', ''));
        $password = (string)$request->post('password', '');
        $rateLimitId = $this->rateLimiter->identifierFor($email);

        $error = null;
        if ($this->rateLimiter->tooManyAttempts($rateLimitId)) {
            $error = __('auth.too_many_attempts');
        } else {
            // status = 'active' excludes disabled/deleted accounts from
            // logging in at all, even with the correct password.
            $stmt = $this->pdo->prepare("SELECT * FROM customers WHERE email = ? AND status = 'active'");
            $stmt->execute([$email]);
            $customer = $stmt->fetch();

            // password_verify() does the bcrypt comparison; a missing
            // customer still reaches this check (short-circuited by &&)
            // rather than being handled as a separate branch, which avoids
            // giving a timing/behavioral hint about whether the email exists.
            if ($customer && password_verify($password, $customer['password_hash'])) {
                $this->rateLimiter->clearAttempts($rateLimitId);
                // Session-fixation defense: issue a brand new session id and
                // CSRF token at the moment of login, so a session id an
                // attacker may have fixed/known before authentication is
                // never valid afterward.
                $this->request->session()->regenerate();
                $this->csrf->rotate();
                $this->request->session()->set('customer_id', (int)$customer['id']);
                // deletion_warning_sent_at = NULL cancels any pending GDPR
                // inactivity-deletion warning now that the customer has
                // proven the account is still in use.
                $this->pdo->prepare('UPDATE customers SET last_login_at = NOW(), deletion_warning_sent_at = NULL WHERE id = ?')->execute([$customer['id']]);
                // Clears CustomerAuth's per-request cached "current customer"
                // so the next read reflects the session we just set, rather
                // than a stale null cached from earlier in this same request.
                CustomerAuth::forget();
                return $this->redirect('/account');
            }
            $this->rateLimiter->recordFailedAttempt($rateLimitId);
            $error = __('auth.invalid_credentials');
        }

        return $this->render('auth/login', ['error' => $error, 'pageTitle' => __('auth.sign_in')]);
    }

    /** Shows the registration form - redirects away if the visitor is already signed in. */
    public function showRegister(Request $request): Response
    {
        if (CustomerAuth::check()) {
            return $this->redirect('/account');
        }
        return $this->render('auth/register', ['errors' => [], 'pageTitle' => __('nav.create_account')]);
    }

    /** Validates and creates a new customer account, then immediately logs them in (same session-fixation defenses as login()) and sends a welcome email. */
    public function register(Request $request): Response
    {
        if (CustomerAuth::check()) {
            return $this->redirect('/account');
        }
        if ($csrfFailure = $this->requireCsrf()) {
            return $csrfFailure;
        }

        $firstName = trim((string)$request->post('first_name', ''));
        $lastName = trim((string)$request->post('last_name', ''));
        $email = filter_var($request->post('email', ''), FILTER_VALIDATE_EMAIL);
        $password = (string)$request->post('password', '');

        $errors = [];
        if (!$firstName || !$lastName) {
            $errors[] = __('validation.full_name_required');
        }
        if (!$email) {
            $errors[] = __('validation.valid_email_required');
        }
        if (strlen($password) < 8) {
            $errors[] = __('validation.password_min_length');
        }

        // Only check for a duplicate email once the basic fields are
        // already valid - no point querying the DB for a submission that
        // would be rejected anyway.
        if (!$errors) {
            $stmt = $this->pdo->prepare('SELECT id FROM customers WHERE email = ?');
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $errors[] = __('validation.email_already_exists');
            }
        }

        if (!$errors) {
            // password_hash(...,PASSWORD_DEFAULT) is bcrypt (PHP's
            // currently-recommended default algorithm) - the plaintext
            // password itself is never stored.
            $stmt = $this->pdo->prepare('INSERT INTO customers (first_name, last_name, email, password_hash, language) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$firstName, $lastName, $email, password_hash($password, PASSWORD_DEFAULT), I18n::current()]);
            $newCustomerId = (int)$this->pdo->lastInsertId();
            // Same session-fixation defenses as login() - a freshly
            // registered account is a fresh authentication event too.
            $this->request->session()->regenerate();
            $this->csrf->rotate();
            $this->request->session()->set('customer_id', $newCustomerId);
            CustomerAuth::forget();
            $this->flash('success', __('auth.welcome', ['name' => $firstName]));
            \Mailer::sendRegistrationWelcome($newCustomerId);
            return $this->redirect('/account');
        }

        return $this->render('auth/register', ['errors' => $errors, 'pageTitle' => __('nav.create_account')]);
    }

    /** Ends the customer's session and sends them back to the storefront home page. */
    public function logout(Request $request): Response
    {
        $this->request->session()->remove('customer_id');
        CustomerAuth::forget();
        $this->flash('success', 'You have been signed out.');
        return $this->redirect('/');
    }

    /** Shows the "enter your email to reset your password" form. */
    public function showForgotPassword(Request $request): Response
    {
        return $this->render('auth/forgot_password', ['submitted' => false, 'pageTitle' => __('auth.reset_password')]);
    }

    /**
     * Emails a password-reset link to the given address if an active
     * account exists for it - but always shows the same "check your
     * email" response either way, and rate-limits attempts, so this form
     * can't be used to discover which email addresses have accounts or to
     * spam a victim's inbox with reset emails.
     */
    public function forgotPassword(Request $request): Response
    {
        if ($csrfFailure = $this->requireCsrf()) {
            return $csrfFailure;
        }

        $email = trim((string)$request->post('email', ''));
        $rateLimitId = $this->rateLimiter->identifierFor($email);

        // Also throttled (not just login) - otherwise this form is an
        // unlimited email-bombing / token-guessing surface on its own,
        // even though the "check your email" response never confirms the
        // address exists.
        if (!$this->rateLimiter->tooManyAttempts($rateLimitId, 5, 15)) {
            $this->rateLimiter->recordFailedAttempt($rateLimitId);

            $stmt = $this->pdo->prepare("SELECT * FROM customers WHERE email = ? AND status = 'active'");
            $stmt->execute([$email]);
            $customer = $stmt->fetch();

            if ($customer) {
                // random_bytes(32) is a cryptographically secure random
                // value (32 bytes = 256 bits) - bin2hex() turns it into a
                // URL-safe hex string; unguessable, unlike e.g. a short
                // numeric code. The token expires after 1 hour so an old,
                // unused reset link/email can't be replayed indefinitely.
                $token = bin2hex(random_bytes(32));
                $this->pdo->prepare('UPDATE customers SET password_reset_token = ?, password_reset_expires_at = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id = ?')
                    ->execute([$token, $customer['id']]);
                \Mailer::sendPasswordReset($customer, $token);
            }
        }

        // Always show the same "check your email" message whether or not
        // the address exists (or the request was throttled) - avoids
        // leaking which emails have accounts.
        return $this->render('auth/forgot_password', ['submitted' => true, 'pageTitle' => __('auth.reset_password')]);
    }

    /** Shows the "set a new password" form for a reset link's token - resetPasswordView() looks the token up so the view can tell an expired/invalid link from a valid one. */
    public function showResetPassword(Request $request): Response
    {
        return $this->resetPasswordView($request, [], false);
    }

    /**
     * Validates the reset token (present and not yet expired) and the new
     * password, then updates the customer's password and invalidates the
     * token so it can't be reused. This does NOT log the customer in
     * automatically - they're shown a success message and go to the
     * normal login form next.
     */
    public function resetPassword(Request $request): Response
    {
        $token = (string)($request->post('token') ?? $request->get('token', ''));

        // password_reset_expires_at > NOW() means an expired token simply
        // fails to match any row here, same as a wrong/unknown token -
        // both end up with $customer === null below.
        $stmt = $this->pdo->prepare(
            "SELECT * FROM customers WHERE password_reset_token = ? AND password_reset_expires_at > NOW() AND status = 'active'"
        );
        $stmt->execute([$token]);
        $customer = $stmt->fetch();

        if ($csrfFailure = $this->requireCsrf()) {
            return $csrfFailure;
        }

        $errors = [];
        if (!$customer) {
            $errors[] = __('auth.reset_link_invalid');
        } else {
            $password = (string)$request->post('password', '');
            $confirm = (string)$request->post('password_confirm', '');
            if (strlen($password) < 8) {
                $errors[] = __('validation.password_min_length');
            }
            if ($password !== $confirm) {
                $errors[] = __('validation.passwords_mismatch');
            }

            if (!$errors) {
                // Clearing password_reset_token/expires_at on success means
                // this same link can never be used a second time, even
                // within its 1-hour window.
                $this->pdo->prepare('UPDATE customers SET password_hash = ?, password_reset_token = NULL, password_reset_expires_at = NULL WHERE id = ?')
                    ->execute([password_hash($password, PASSWORD_DEFAULT), $customer['id']]);
                return $this->render('auth/reset_password', [
                    'success' => true, 'customer' => $customer, 'errors' => [], 'token' => $token,
                    'pageTitle' => __('auth.reset_password'),
                ]);
            }
        }

        return $this->render('auth/reset_password', [
            'success' => false, 'customer' => $customer, 'errors' => $errors, 'token' => $token,
            'pageTitle' => __('auth.reset_password'),
        ]);
    }

    /** Shared lookup-and-render for the reset password view (used by both showResetPassword() and as the fallback render inside resetPassword()) - looks up the token so the template can tell an unknown/expired link apart from a valid one still awaiting a new password. */
    private function resetPasswordView(Request $request, array $errors, bool $success): Response
    {
        $token = (string)($request->get('token') ?? $request->post('token', ''));
        $stmt = $this->pdo->prepare(
            "SELECT * FROM customers WHERE password_reset_token = ? AND password_reset_expires_at > NOW() AND status = 'active'"
        );
        $stmt->execute([$token]);
        $customer = $stmt->fetch();

        return $this->render('auth/reset_password', [
            'success' => $success, 'customer' => $customer, 'errors' => $errors, 'token' => $token,
            'pageTitle' => __('auth.reset_password'),
        ]);
    }
}
