<?php
/**
 * Storefront CMS page renderer - shows a single admin-authored page (e.g.
 * "About Us", "Right of Withdrawal", "Terms") at /page/{slug}.
 * Rendered by Controllers\Storefront\PageController::show(); this file is
 * just the body, wrapped by the active theme's header.php/footer.php via
 * Core\Renderer::render().
 *
 * @var \ShopRex\Models\Page $page The already-loaded page for the current
 *                                  language/slug - ->title and ->content
 *                                  are the only fields this view reads.
 */
?>

<div class="row justify-content-center">
  <div class="col-lg-9">
    <h1 class="h3 mb-4"><?= e($page->title) ?></h1>
    <div class="cms-content">
      <?php // Rendered as trusted HTML (from the admin-only Quill editor in
            // admin/pages.php) - NOT escaped. See page.php's original
            // comment / CLAUDE.md's "Security posture" section: this is a
            // documented, intentional trust boundary, not a bug. ?>
      <?= $page->content ?>
    </div>
  </div>
</div>
