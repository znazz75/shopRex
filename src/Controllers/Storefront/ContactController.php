<?php

namespace ShopRex\Controllers\Storefront;

use ShopRex\Core\Auth\CustomerAuth;
use ShopRex\Core\Container;
use ShopRex\Core\Controller;
use ShopRex\Core\Request;
use ShopRex\Core\Response;
use ShopRex\Models\ContactMessage;
use ShopRex\Services\I18n;
use ShopRex\Services\RateLimiter;

/**
 * New in v2.00. Honeypot field + Services\RateLimiter (5/60min, IP+email
 * keyed, same pattern as login) + CSRF - no external spam service, matching
 * the project's zero-dependency posture.
 */
final class ContactController extends Controller
{
    private readonly \PDO $pdo;
    private readonly RateLimiter $rateLimiter;

    public function __construct(Request $request, Container $container)
    {
        parent::__construct($request, $container);
        $this->pdo = $container->make(\PDO::class);
        $this->rateLimiter = $container->make('RateLimiter.contact');
    }

    public function show(Request $request): Response
    {
        $customer = CustomerAuth::current();
        return $this->render('contact/show', [
            'errors' => [], 'sent' => false,
            'name' => trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? '')),
            'email' => $customer['email'] ?? '',
            'pageTitle' => __('contact.title'),
        ]);
    }

    public function submit(Request $request): Response
    {
        if ($csrfFailure = $this->requireCsrf()) {
            return $csrfFailure;
        }

        $customer = CustomerAuth::current();

        // Honeypot - a real visitor never fills in a field hidden with CSS,
        // a bot filling every field blind does. Silently pretend success
        // rather than telling an attacker what tripped.
        if (trim((string)$request->post('website', '')) !== '') {
            return $this->render('contact/show', [
                'errors' => [], 'sent' => true, 'name' => '', 'email' => '', 'pageTitle' => __('contact.title'),
            ]);
        }

        $name = trim((string)$request->post('name', ''));
        $email = filter_var($request->post('email', ''), FILTER_VALIDATE_EMAIL);
        $subject = trim((string)$request->post('subject', ''));
        $message = trim((string)$request->post('message', ''));
        $orderNumber = trim((string)$request->post('order_number', ''));

        $errors = [];
        if ($name === '') {
            $errors[] = __('validation.full_name_required');
        }
        if (!$email) {
            $errors[] = __('validation.valid_email_required');
        }
        if ($message === '') {
            $errors[] = __('contact.message_required');
        }

        if (!$errors) {
            $rateLimitId = $this->rateLimiter->identifierFor($email);
            if ($this->rateLimiter->tooManyAttempts($rateLimitId, 5, 60)) {
                $errors[] = __('auth.too_many_attempts');
            }
        }

        if ($errors) {
            return $this->render('contact/show', [
                'errors' => $errors, 'sent' => false, 'name' => $name, 'email' => (string)$request->post('email', ''),
                'pageTitle' => __('contact.title'),
            ]);
        }

        $this->rateLimiter->recordFailedAttempt($this->rateLimiter->identifierFor($email));

        $contactMessage = ContactMessage::submit([
            'name' => $name, 'email' => $email, 'subject' => $subject, 'message' => $message,
            'order_number' => $orderNumber, 'customer_id' => $customer['id'] ?? null, 'ip_address' => $request->ip(),
        ], $this->pdo);

        $lang = I18n::current();
        $shopEmail = $this->container->make(\ShopRex\Services\SettingsRepository::class)->get('shop_email', ADMIN_EMAIL);

        $notifyShop = \Mailer::render('contact_form_notify_shop', $lang, [
            'name' => e($name), 'email' => e($email), 'subject' => e($subject ?: '(no subject)'), 'message' => nl2br(e($message)),
        ]);
        \Mailer::send((string)$shopEmail, $notifyShop['subject'], $notifyShop['html'], 'contact_form_notify_shop');

        $confirmation = \Mailer::render('contact_form_confirmation', $lang, ['name' => e($name)]);
        \Mailer::send($email, $confirmation['subject'], $confirmation['html'], 'contact_form_confirmation');

        return $this->render('contact/show', [
            'errors' => [], 'sent' => true, 'name' => '', 'email' => '', 'pageTitle' => __('contact.title'),
        ]);
    }
}
