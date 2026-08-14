<?php
/**
 * Storefront header. Expects $pageTitle to optionally be set before include.
 */
$mainMenu = getMenuTree('main');
$customer = currentCustomer();
$theme = getActiveTheme();
$shopName = getSetting('shop_name', SITE_NAME);
$searchQuery = $_GET['q'] ?? '';
$currentLang = getCurrentLanguage();
$availableLangs = getAvailableLanguages();

/**
 * Recursively render the main menu as Bootstrap nav links / dropdowns so
 * any depth of nesting (added in Admin -> Menus) is reachable.
 */
function renderMainMenu(array $nodes): void
{
    foreach ($nodes as $node) {
        $children = $node['children'] ?? [];
        if ($children) {
            echo '<li class="nav-item dropdown">';
            echo '<a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">' . e($node['label']) . '</a>';
            echo '<ul class="dropdown-menu">';
            renderMenuDropdownItems($children);
            echo '</ul></li>';
        } else {
            echo '<li class="nav-item"><a class="nav-link" href="' . e(resolveMenuUrl($node)) . '"' . ($node['open_new_tab'] ? ' target="_blank" rel="noopener"' : '') . '>' . e($node['label']) . '</a></li>';
        }
    }
}

function renderMenuDropdownItems(array $nodes): void
{
    foreach ($nodes as $node) {
        $children = $node['children'] ?? [];
        echo '<li><a class="dropdown-item" href="' . e(resolveMenuUrl($node)) . '"' . ($node['open_new_tab'] ? ' target="_blank" rel="noopener"' : '') . '>' . e($node['label']) . '</a></li>';
        if ($children) {
            foreach ($children as $child) {
                echo '<li><a class="dropdown-item ps-4" href="' . e(resolveMenuUrl($child)) . '">' . e($child['label']) . '</a></li>';
            }
        }
    }
}
?>
<!doctype html>
<html lang="<?= e($currentLang) ?>" data-bs-theme="<?= e($theme['bs_theme']) ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= isset($pageTitle) ? e($pageTitle) . ' - ' : '' ?><?= e($shopName) ?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <link rel="stylesheet" href="<?= rtrim(SITE_URL, '/') ?>/assets/css/style.css">
  <style>:root{--shop-accent: <?= e($theme['accent']) ?>;}</style>
  <?= themeStylesheetTag() ?>
</head>
<body>
<header>
  <nav class="navbar navbar-expand-lg navbar-dark sticky-top shadow-sm" style="background-color:<?= e($theme['navbar_bg']) ?>;">
    <div class="container">
      <a class="navbar-brand fw-bold" href="<?= rtrim(SITE_URL, '/') ?>/index.php"><?= e($shopName) ?></a>
      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavCollapse" aria-controls="mainNavCollapse" aria-expanded="false" aria-label="Toggle navigation">
        <span class="navbar-toggler-icon"></span>
      </button>
      <div class="collapse navbar-collapse" id="mainNavCollapse">
        <ul class="navbar-nav me-auto mb-2 mb-lg-0">
          <?php renderMainMenu($mainMenu); ?>
        </ul>
        <form class="d-flex me-2 mb-2 mb-lg-0" role="search" method="get" action="<?= rtrim(SITE_URL, '/') ?>/search.php">
          <input class="form-control form-control-sm" type="search" name="q" placeholder="<?= e(__('nav.search_placeholder')) ?>" value="<?= e($searchQuery) ?>" style="min-width:220px;">
          <button class="btn btn-sm btn-outline-light ms-2" type="submit"><i class="bi bi-search"></i></button>
        </form>
        <ul class="navbar-nav align-items-lg-center gap-lg-2">
          <?php if (count($availableLangs) > 1): ?>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
              <i class="bi bi-globe2"></i> <?= e(strtoupper($currentLang)) ?>
            </a>
            <ul class="dropdown-menu dropdown-menu-end">
              <?php foreach ($availableLangs as $code => $label): ?>
                <li><a class="dropdown-item<?= $code === $currentLang ? ' active' : '' ?>" href="<?= e(languageSwitchUrl($code)) ?>"><?= e($label) ?></a></li>
              <?php endforeach; ?>
            </ul>
          </li>
          <?php endif; ?>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
              <i class="bi bi-person-circle"></i> <?= $customer ? e($customer['first_name']) : e(__('nav.account')) ?>
            </a>
            <ul class="dropdown-menu dropdown-menu-end">
              <?php if ($customer): ?>
                <li><a class="dropdown-item" href="<?= rtrim(SITE_URL, '/') ?>/account.php"><?= e(__('nav.my_account')) ?></a></li>
                <li><a class="dropdown-item" href="<?= rtrim(SITE_URL, '/') ?>/logout.php"><?= e(__('nav.logout')) ?></a></li>
              <?php else: ?>
                <li><a class="dropdown-item" href="<?= rtrim(SITE_URL, '/') ?>/login.php"><?= e(__('nav.login')) ?></a></li>
                <li><a class="dropdown-item" href="<?= rtrim(SITE_URL, '/') ?>/register.php"><?= e(__('nav.create_account')) ?></a></li>
              <?php endif; ?>
            </ul>
          </li>
          <li class="nav-item">
            <a class="nav-link position-relative" href="<?= rtrim(SITE_URL, '/') ?>/cart.php">
              <i class="bi bi-cart3 fs-5"></i>
              <?php if (Cart::count() > 0): ?>
                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"><?= Cart::count() ?></span>
              <?php endif; ?>
            </a>
          </li>
        </ul>
      </div>
    </div>
  </nav>
  <?php if ($customer && !empty($customer['is_test_account'])): ?>
    <div class="bg-warning text-dark text-center small py-1">
      <i class="bi bi-flask me-1"></i><strong><?= e(__('nav.test_mode_title')) ?></strong> &mdash; <?= e(__('nav.test_mode_text')) ?>
    </div>
  <?php endif; ?>
</header>
<main class="container py-4">
  <?php foreach (getFlashes() as $flash): ?>
    <div class="alert alert-<?= e($flash['type'] === 'error' ? 'danger' : ($flash['type'] === 'success' ? 'success' : 'info')) ?> alert-dismissible fade show" role="alert">
      <?= e($flash['message']) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
  <?php endforeach; ?>
