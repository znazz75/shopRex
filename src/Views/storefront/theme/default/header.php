<?php
/**
 * Default theme package's header slot (Core\ThemeManager::resolve()).
 * Opens the <html>/<head>/<body>, renders the top navbar (brand, main
 * menu, search box, language switcher, account dropdown, cart icon), the
 * test-mode banner, and any queued flash messages - then leaves <main>
 * open for the page body that follows (footer.php closes it).
 *
 * This file is deliberately NOT rewritten/ported to a class-based view -
 * it's required directly by every one of Core\Renderer::render()/slot()/
 * renderSlot() so that every storefront page (and the homepage's slot-only
 * render) shares byte-for-byte identical chrome. Every variable it uses
 * either comes from the global helpers in src/view-helpers.php (safe to
 * call from any view) or is computed fresh right below, in the block
 * before the closing `?>` - nothing here is passed in by a controller
 * except the optional $pageTitle.
 *
 * @var string|null $pageTitle Optional - set by the calling controller's
 *                              render() data array. When present it's
 *                              prefixed onto the <title> tag ahead of the
 *                              shop name; when absent the <title> is just
 *                              the shop name on its own.
 */

use ShopRex\Support\StorefrontMenuRenderer;

// Everything below is derived fresh on every request (no caching beyond
// what the underlying services already do) so the header always reflects
// the current session/settings, even though this file is shared byte-for-
// byte across every page.
$mainMenu = getMenuTree('main'); // Nested tree of active 'main'-location menu items - StorefrontMenuRenderer walks it recursively below.
$customer = currentCustomer(); // Logged-in customer's row (id/first_name/is_test_account/...), or null if a guest.
$theme = getActiveTheme(); // Color-accent theme (accent color + navbar background), independent of the layout-package theme this file itself belongs to - see CLAUDE.md's "Themes" section.
$shopName = getSetting('shop_name', SITE_NAME);
$searchQuery = $_GET['q'] ?? ''; // Re-read directly from the query string (not routed through Request) so the search box can redisplay whatever was just searched for, on the search-results page itself.
$currentLang = getCurrentLanguage();
$availableLangs = getEnabledLanguages(); // Only admin-enabled languages - the language switcher must never offer a disabled one (see CLAUDE.md's i18n section).
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
      <a class="navbar-brand fw-bold" href="<?= rtrim(SITE_URL, '/') ?>/"><?= e($shopName) ?></a>
      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavCollapse" aria-controls="mainNavCollapse" aria-expanded="false" aria-label="Toggle navigation">
        <span class="navbar-toggler-icon"></span>
      </button>
      <div class="collapse navbar-collapse" id="mainNavCollapse">
        <ul class="navbar-nav me-auto mb-2 mb-lg-0">
          <?php // Delegates the whole (potentially nested, e.g. category
                // submenus) main menu markup to a static renderer rather than
                // looping here - see Support\StorefrontMenuRenderer. It uses
                // resolveMenuUrl() internally to turn each item's link_type/
                // link_value into an actual href. ?>
          <?php StorefrontMenuRenderer::renderMain($mainMenu); ?>
        </ul>
        <form class="d-flex me-2 mb-2 mb-lg-0" role="search" method="get" action="<?= rtrim(SITE_URL, '/') ?>/search">
          <input class="form-control form-control-sm" type="search" name="q" placeholder="<?= e(__('nav.search_placeholder')) ?>" value="<?= e($searchQuery) ?>" style="min-width:220px;">
          <button class="btn btn-sm btn-outline-light ms-2" type="submit"><i class="bi bi-search"></i></button>
        </form>
        <ul class="navbar-nav align-items-lg-center gap-lg-2">
          <?php // Only show the language dropdown at all when there's more
                // than one enabled language to switch to - a single-language
                // shop doesn't need it cluttering the navbar. ?>
          <?php if (count($availableLangs) > 1): ?>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
              <i class="bi bi-globe2"></i> <?= e(strtoupper($currentLang)) ?>
            </a>
            <ul class="dropdown-menu dropdown-menu-end">
              <?php // languageSwitchUrl() builds a link that re-requests the
                    // current page in a different language (see I18n::switchUrl())
                    // - "active" just highlights whichever one we're on now. ?>
              <?php foreach ($availableLangs as $code => $label): ?>
                <li><a class="dropdown-item<?= $code === $currentLang ? ' active' : '' ?>" href="<?= e(languageSwitchUrl($code)) ?>"><?= e($label) ?></a></li>
              <?php endforeach; ?>
            </ul>
          </li>
          <?php endif; ?>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
              <?php // Logged-in visitors see their own first name instead of
                    // the generic "Account" label as a quick logged-in cue. ?>
              <i class="bi bi-person-circle"></i> <?= $customer ? e($customer['first_name']) : e(__('nav.account')) ?>
            </a>
            <ul class="dropdown-menu dropdown-menu-end">
              <?php // Swap the whole dropdown's contents based on login state
                    // - logged-in gets account/logout links, guests get
                    // login/register links. ?>
              <?php if ($customer): ?>
                <li><a class="dropdown-item" href="<?= rtrim(SITE_URL, '/') ?>/account"><?= e(__('nav.my_account')) ?></a></li>
                <li><a class="dropdown-item" href="<?= rtrim(SITE_URL, '/') ?>/logout"><?= e(__('nav.logout')) ?></a></li>
              <?php else: ?>
                <li><a class="dropdown-item" href="<?= rtrim(SITE_URL, '/') ?>/login"><?= e(__('nav.login')) ?></a></li>
                <li><a class="dropdown-item" href="<?= rtrim(SITE_URL, '/') ?>/register"><?= e(__('nav.create_account')) ?></a></li>
              <?php endif; ?>
            </ul>
          </li>
          <li class="nav-item">
            <a class="nav-link position-relative" href="<?= rtrim(SITE_URL, '/') ?>/cart">
              <i class="bi bi-cart3 fs-5"></i>
              <?php // Cart::count() (the legacy includes/Cart.php class, kept
                    // as-is - see CLAUDE.md's "Legacy classes kept as-is")
                    // sums line-item quantities from $_SESSION['cart']; only
                    // show the little red badge once there's at least 1 item. ?>
              <?php if (Cart::count() > 0): ?>
                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"><?= Cart::count() ?></span>
              <?php endif; ?>
            </a>
          </li>
        </ul>
      </div>
    </div>
  </nav>
  <?php // Site-wide reminder banner: shown on every page while a
        // "is_test_account" customer (see CLAUDE.md's "Test accounts"
        // section) is logged in, so they can't forget they're in a mode
        // where orders don't decrement real stock or count in reports. ?>
  <?php if ($customer && !empty($customer['is_test_account'])): ?>
    <div class="bg-warning text-dark text-center small py-1">
      <i class="bi bi-flask me-1"></i><strong><?= e(__('nav.test_mode_title')) ?></strong> &mdash; <?= e(__('nav.test_mode_text')) ?>
    </div>
  <?php endif; ?>
</header>
<main class="container py-4">
  <?php // One-shot flash messages queued by the previous request (e.g. "item
        // added to cart", "order placed", a validation error after a
        // redirect) - getFlashes() both reads AND clears them (FlashBag::pull()),
        // so they show exactly once and won't reappear on a page refresh.
        // Bootstrap's alert color class is picked from the flash's 'type'. ?>
  <?php foreach (getFlashes() as $flash): ?>
    <div class="alert alert-<?= e($flash['type'] === 'error' ? 'danger' : ($flash['type'] === 'success' ? 'success' : 'info')) ?> alert-dismissible fade show" role="alert">
      <?= e($flash['message']) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
  <?php endforeach; ?>
