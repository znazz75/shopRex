<?php
/**
 * Storefront "order not found" page body.
 *
 * Rendered by Controllers\Storefront\OrderController when an order lookup
 * (e.g. the confirmation page, or looking up an order by number/email)
 * fails to match anything - wrong/expired link, wrong order number, etc.
 * Just the body; Core\Renderer::render() wraps it in the theme's normal
 * header.php/footer.php chrome.
 *
 * @var string $pageTitle Page <title>/heading text - consumed by
 *                         header.php, not this file (see product/not_found.php
 *                         for the full explanation of why it's in scope here).
 */
?>
<p><?= e(__('order.not_found_text')) ?></p>
