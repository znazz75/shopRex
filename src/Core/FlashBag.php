<?php

namespace ShopRex\Core;

/**
 * Direct port of setFlash()/getFlashes() from includes/functions.php -
 * one-shot notices shown once after a redirect, then discarded.
 *
 * In plain terms: a "flash message" is the little banner you see after an
 * action like "Product saved successfully" - it's set right before a
 * redirect, survives exactly one page load, and then disappears even if the
 * user refreshes. This class exists separately from Session so that
 * "temporary one-shot notices" has one clear, purpose-built API instead of
 * every controller reinventing its own $_SESSION['flash'] handling.
 */
final class FlashBag
{
    public function __construct(private readonly Session $session)
    {
    }

    /** Queues a new flash message of a given $type (e.g. 'success', 'error') to be shown on the next page render. */
    public function add(string $type, string $message): void
    {
        // Read the existing queue (defaulting to empty), append the new
        // message, and write the whole queue back - this lets multiple
        // flash messages stack up before a single redirect (e.g. one
        // success + one warning).
        $flashes = $this->session->get('flash', []);
        $flashes[] = ['type' => $type, 'message' => $message];
        $this->session->set('flash', $flashes);
    }

    /**
     * Returns every queued flash message and clears the queue in the same
     * step (via Session::pull()), so the templates that call this to render
     * the messages automatically consume them - a page refresh afterwards
     * won't show them again.
     * @return array<int, array{type: string, message: string}>
     */
    public function pull(): array
    {
        return $this->session->pull('flash', []);
    }
}
