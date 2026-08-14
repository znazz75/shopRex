<?php
/** @var \ShopRex\Models\Page $page */
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
