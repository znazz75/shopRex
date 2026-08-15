<?php
/**
 * Storefront "CMS page not found" page body.
 *
 * Rendered by Controllers\Storefront\PageController when /page/{slug}
 * doesn't match any published row in the pages table. Just the body;
 * Core\Renderer::render() wraps it in the theme's header.php/footer.php.
 *
 * @var string $pageTitle Page <title>/heading text - consumed by
 *                         header.php, not this file (see product/not_found.php
 *                         for the full explanation of why it's in scope here).
 */
?>
<div class="alert alert-warning"><?= e(__('page.not_found_text')) ?></div>
