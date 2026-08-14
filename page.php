<?php
require_once __DIR__ . '/includes/bootstrap.php';

$slug = $_GET['slug'] ?? '';
$lang = getCurrentLanguage();

// Prefer the visitor's language, fall back to the site default language,
// then to whatever exists for this slug - a missing translation degrades
// gracefully instead of 404ing.
$stmt = db()->prepare('SELECT * FROM pages WHERE slug = ? AND language = ?');
$stmt->execute([$slug, $lang]);
$page = $stmt->fetch();

if (!$page) {
    $defaultLang = getSetting('default_language', 'en');
    if ($defaultLang !== $lang) {
        $stmt->execute([$slug, $defaultLang]);
        $page = $stmt->fetch();
    }
}
if (!$page) {
    $stmt = db()->prepare('SELECT * FROM pages WHERE slug = ? LIMIT 1');
    $stmt->execute([$slug]);
    $page = $stmt->fetch();
}

if (!$page) {
    http_response_code(404);
    $pageTitle = __('page.not_found_title');
    require themeTemplatePath('header.php');
    echo '<div class="alert alert-warning">Sorry, this page could not be found.</div>';
    require themeTemplatePath('footer.php');
    exit;
}

$pageTitle = $page['title'];
require themeTemplatePath('header.php');
?>

<div class="row justify-content-center">
  <div class="col-lg-9">
    <h1 class="h3 mb-4"><?= e($page['title']) ?></h1>
    <div class="cms-content">
      <?php // Rendered as trusted HTML (from the admin-only Quill editor in
            // admin/pages.php), matching how most CMS post/page content
            // works - it is NOT escaped, so treat "who can edit pages"
            // (Admin -> Admin Accounts) as equivalent to "who can inject
            // markup into the storefront". ?>
      <?= $page['content'] ?>
    </div>
  </div>
</div>

<?php require themeTemplatePath('footer.php'); ?>
