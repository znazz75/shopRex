<?php
/**
 * Storefront "product not found" page body.
 *
 * Rendered by Controllers\Storefront\ProductController when the requested
 * /product/{slug} doesn't match any row in the products table (typo'd or
 * stale link, unpublished/deleted product, etc). This file is only the
 * body - Core\Renderer::render() wraps it with the active theme's
 * header.php/footer.php, so the page still gets the normal nav/footer
 * chrome around this one warning box.
 *
 * @var string $pageTitle Page <title>/heading text - not used directly in
 *                         this file, but read out of scope by header.php
 *                         (it's extract()-ed into every file in the
 *                         header+body+footer render, not just this one).
 */
?>
<div class="alert alert-warning"><?= e(__('product.not_found_text')) ?></div>
