<?php

namespace ShopRex\Core;

/**
 * Direct port of the csrfToken()/csrfField()/verifyCsrf()/requireCsrf()
 * functions from includes/functions.php - logic unchanged (including the
 * hash_equals('', '') footgun comment below), just relocated onto a class.
 *
 * In plain terms: CSRF (Cross-Site Request Forgery) protection stops another
 * website from tricking a logged-in user's browser into submitting a form on
 * this site without their knowledge (e.g. a hidden auto-submitting form on
 * an attacker's page that posts to "/cart/add" or "/admin/products/delete").
 * The fix is a secret, unpredictable token embedded in every form; the
 * attacker's page can't know that token, so a forged submission fails the
 * check in verify(). This class exists as its own small class (rather than
 * being folded into Session) so every place that needs CSRF handling
 * (both the OOP controllers and the legacy includes/functions.php shim) can
 * share exactly one implementation and one session key.
 */
final class Csrf
{
    public function __construct(private readonly Session $session)
    {
    }

    /**
     * Returns the current CSRF token for this session, generating and
     * storing a new random one the first time it's called. Reusing the same
     * token for the whole session (rather than a fresh one per form) keeps
     * things simple - e.g. it means a user can have multiple tabs/forms open
     * at once without one submission invalidating another.
     */
    public function token(): string
    {
        $token = $this->session->get('csrf_token');
        if (empty($token)) {
            // random_bytes() is a cryptographically secure random source -
            // important here since predictability would defeat the whole
            // point of the token.
            $token = bin2hex(random_bytes(32));
            $this->session->set('csrf_token', $token);
        }
        return $token;
    }

    /** Builds the actual hidden <input> HTML that forms embed so the token gets submitted back with the form. */
    public function field(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($this->token(), ENT_QUOTES, 'UTF-8') . '">';
    }

    /**
     * Checks whether a submitted token matches the one stored in this
     * session - call this on every state-changing POST before doing
     * anything with the request. Returns false for anything suspicious
     * (missing, empty, or mismatched token) rather than throwing, so callers
     * decide how to respond (usually reject the request).
     */
    public function verify(?string $submitted): bool
    {
        $sessionToken = (string)$this->session->get('csrf_token', '');
        // Both sides must be a non-empty string before comparing -
        // hash_equals('', '') returns true, which would let a forged
        // request with no token field pass whenever the victim's session
        // happens not to have generated one yet.
        // hash_equals() (rather than ===) is used specifically because it
        // compares in constant time, so an attacker can't use response-time
        // differences to guess the correct token one character at a time
        // (a timing attack).
        return is_string($submitted) && $submitted !== '' && $sessionToken !== '' && hash_equals($sessionToken, $submitted);
    }

    /** Call on every successful login/registration - see Session::regenerate() docblock. */
    public function rotate(): void
    {
        // Removing the token forces the next token() call to mint a fresh
        // one - paired with Session::regenerate() so a session ID (and the
        // CSRF secret tied to it) captured before login can't be reused
        // afterwards.
        $this->session->remove('csrf_token');
    }
}
