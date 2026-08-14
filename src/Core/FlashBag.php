<?php

namespace ShopRex\Core;

/**
 * Direct port of setFlash()/getFlashes() from includes/functions.php -
 * one-shot notices shown once after a redirect, then discarded.
 */
final class FlashBag
{
    public function __construct(private readonly Session $session)
    {
    }

    public function add(string $type, string $message): void
    {
        $flashes = $this->session->get('flash', []);
        $flashes[] = ['type' => $type, 'message' => $message];
        $this->session->set('flash', $flashes);
    }

    /** @return array<int, array{type: string, message: string}> */
    public function pull(): array
    {
        return $this->session->pull('flash', []);
    }
}
