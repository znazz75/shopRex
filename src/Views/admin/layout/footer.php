<?php
/**
 * Admin's one fixed layout - the closing half, paired with layout/header.php
 * (which opens the <main class="admin-main"> tag closed on the very first
 * line below, and the .admin-layout wrapper <div> closed on the next line).
 * Core\Renderer::render() requires header.php, then the page's own view
 * file, then this file, in that order, around every admin controller's
 * output - so there's nothing left for this file to do but close the tags
 * header.php opened and load the shared admin JS bundle.
 *
 * No @var entries: this file uses no variables of its own, only emits
 * static markup plus the SITE_URL constant.
 */
?>
  </main>
</div>
<script src="<?= rtrim(SITE_URL, '/') ?>/admin/assets/js/admin.js"></script>
</body>
</html>
