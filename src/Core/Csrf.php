<?php

namespace ShopRex\Core;

/**
 * Direct port of the csrfToken()/csrfField()/verifyCsrf()/requireCsrf()
 * functions from includes/functions.php - logic unchanged (including the
 * hash_equals('', '') footgun comment below), just relocated onto a class.
 */
final class Csrf
{
    public function __construct(private readonly Session $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get('csrf_token');
        if (empty($token)) {
            $token = bin2hex(random_bytes(32));
            $this->session->set('csrf_token', $token);
        }
        return $token;
    }

    public function field(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($this->token(), ENT_QUOTES, 'UTF-8') . '">';
    }

    public function verify(?string $submitted): bool
    {
        $sessionToken = (string)$this->session->get('csrf_token', '');
        // Both sides must be a non-empty string before comparing -
        // hash_equals('', '') returns true, which would let a forged
        // request with no token field pass whenever the victim's session
        // happens not to have generated one yet.
        return is_string($submitted) && $submitted !== '' && $sessionToken !== '' && hash_equals($sessionToken, $submitted);
    }

    /** Call on every successful login/registration - see Session::regenerate() docblock. */
    public function rotate(): void
    {
        $this->session->remove('csrf_token');
    }
}
