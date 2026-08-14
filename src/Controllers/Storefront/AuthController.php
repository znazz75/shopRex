<?php

namespace ShopRex\Controllers\Storefront;

use ShopRex\Core\Auth\CustomerAuth;
use ShopRex\Core\Container;
use ShopRex\Core\Controller;
use ShopRex\Core\Request;
use ShopRex\Core\Response;
use ShopRex\Services\I18n;
use ShopRex\Services\RateLimiter;

/** Direct port of login.php + register.php + logout.php + forgot_password.php + reset_password.php. */
final class AuthController extends Controller
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
        if (CustomerAuth::check()) {
            return $this->redirect('/account');
        }
        return $this->render('auth/login', ['error' => null, 'pageTitle' => __('auth.sign_in')]);
    }

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
            $stmt = $this->pdo->prepare("SELECT * FROM customers WHERE email = ? AND status = 'active'");
            $stmt->execute([$email]);
            $customer = $stmt->fetch();

            if ($customer && password_verify($password, $customer['password_hash'])) {
                $this->rateLimiter->clearAttempts($rateLimitId);
                $this->request->session()->regenerate();
                $this->csrf->rotate();
                $this->request->session()->set('customer_id', (int)$customer['id']);
                $this->pdo->prepare('UPDATE customers SET last_login_at = NOW(), deletion_warning_sent_at = NULL WHERE id = ?')->execute([$customer['id']]);
                CustomerAuth::forget();
                return $this->redirect('/account');
            }
            $this->rateLimiter->recordFailedAttempt($rateLimitId);
            $error = __('auth.invalid_credentials');
        }

        return $this->render('auth/login', ['error' => $error, 'pageTitle' => __('auth.sign_in')]);
    }

    public function showRegister(Request $request): Response
    {
        if (CustomerAuth::check()) {
            return $this->redirect('/account');
        }
        return $this->render('auth/register', ['errors' => [], 'pageTitle' => __('nav.create_account')]);
    }

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

        if (!$errors) {
            $stmt = $this->pdo->prepare('SELECT id FROM customers WHERE email = ?');
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $errors[] = __('validation.email_already_exists');
            }
        }

        if (!$errors) {
            $stmt = $this->pdo->prepare('INSERT INTO customers (first_name, last_name, email, password_hash, language) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$firstName, $lastName, $email, password_hash($password, PASSWORD_DEFAULT), I18n::current()]);
            $newCustomerId = (int)$this->pdo->lastInsertId();
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

    public function logout(Request $request): Response
    {
        $this->request->session()->remove('customer_id');
        CustomerAuth::forget();
        $this->flash('success', 'You have been signed out.');
        return $this->redirect('/');
    }

    public function showForgotPassword(Request $request): Response
    {
        return $this->render('auth/forgot_password', ['submitted' => false, 'pageTitle' => __('auth.reset_password')]);
    }

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

    public function showResetPassword(Request $request): Response
    {
        return $this->resetPasswordView($request, [], false);
    }

    public function resetPassword(Request $request): Response
    {
        $token = (string)($request->post('token') ?? $request->get('token', ''));

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
