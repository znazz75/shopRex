<?php
/**
 * Admin's one fixed layout - the opening half of every admin page's HTML
 * (sidebar nav + <html>/<head>/<body> wrapper). Its matching closing half
 * is layout/footer.php. Unlike the storefront, admin has no theme
 * "package" concept (Core\ThemeManager has no package/override mechanism
 * on the admin side - see src/container.php's docblock for the
 * 'ThemeManager.storefront' binding used only by the Settings theme
 * picker, which is a *storefront* concern shown on an admin screen) -
 * every admin page uses this exact same layout file, always.
 *
 * How it gets included: Core\Renderer::render() requires this file (and
 * footer.php) directly around whatever view file a controller renders, so
 * every variable a controller's view extract()-ed into scope is *also*
 * visible in here (that's how $pageTitle below "just works" without being
 * passed explicitly).
 *
 * @var string|null $pageTitle Optional, set by the calling view before this
 *                              file is included; shown in the <title> tag.
 *                              When absent, the title falls back to just
 *                              "Admin - <site name>".
 */
// Who's logged in right now (or null - but this file is only ever reached
// past the login-required capability gate, so in practice it's always set).
$admin = currentAdmin();
// Every sidebar nav link, in display order. 'capability' is the string
// checked against Core\Auth\AdminAuth::CAPABILITIES (via adminCan() below)
// to decide whether the currently logged-in admin's role is allowed to
// see that link at all - a 'manager' role only has a handful of these
// capabilities, so most of this list is invisible to them (see the
// adminCan() check in the foreach further down, and CLAUDE.md's "two
// roles" section for the full capability list).
$navItems = [
    '/admin'               => ['label' => __('admin.dashboard'), 'capability' => 'dashboard'],
    '/admin/products'      => ['label' => __('admin.products'), 'capability' => 'products'],
    '/admin/categories'    => ['label' => __('admin.categories'), 'capability' => 'categories'],
    '/admin/inventory'     => ['label' => __('admin.inventory'), 'capability' => 'inventory'],
    '/admin/pages'         => ['label' => __('admin.pages'), 'capability' => 'pages'],
    '/admin/menus'         => ['label' => __('admin.menus'), 'capability' => 'menus'],
    '/admin/email-templates' => ['label' => __('admin.email_templates'), 'capability' => 'settings'],
    '/admin/tax-rates'     => ['label' => __('admin.tax_rates'), 'capability' => 'settings'],
    '/admin/numbering'     => ['label' => __('admin.numbering'), 'capability' => 'settings'],
    '/admin/shipping'      => ['label' => __('admin.shipping'), 'capability' => 'shipping'],
    '/admin/orders'        => ['label' => __('admin.orders'), 'capability' => 'orders'],
    '/admin/finance'       => ['label' => __('admin.finance'), 'capability' => 'finance'],
    '/admin/customers'     => ['label' => __('admin.customers'), 'capability' => 'customers'],
    '/admin/admins'        => ['label' => __('admin.admin_accounts'), 'capability' => 'admins'],
    '/admin/settings'      => ['label' => __('admin.settings'), 'capability' => 'settings'],
    '/admin/contact-messages' => ['label' => __('admin.contact_messages'), 'capability' => 'contact_messages'],
    '/admin/withdrawals'      => ['label' => __('admin.withdrawals'), 'capability' => 'withdrawals'],
    '/admin/rma-tickets'      => ['label' => __('admin.rma_tickets'), 'capability' => 'rma_tickets'],
    '/admin/legal-documents'  => ['label' => __('admin.legal_documents'), 'capability' => 'legal_documents'],
];
// Highlights the *closest-matching* nav entry rather than an exact string
// match, since most sections have detail/edit routes one or more segments
// deeper than their list page (e.g. /admin/orders/5) - a plain === would
// never highlight anything once the visitor drills into a record.
//
// currentPath() (defined in src/view-helpers.php) returns the current
// request's URL path with the site's base path stripped off, e.g. on
// "https://example.com/shop/admin/orders/5" it returns "/admin/orders/5"
// even though the site lives in a "/shop" subdirectory - it duplicates
// (rather than reuses) the same stripping logic as Core\Request::path()
// because a plain view template has no Request object in scope, only
// $_SERVER directly.
$requestPath = currentPath();
$currentNavPath = null;
// Walk every nav link and keep the LONGEST one that is either an exact
// match or a path-prefix of the current URL. Checking every candidate
// (instead of stopping at the first match) matters because, e.g.,
// "/admin/orders" is a prefix of "/admin/orders/5" AND "/admin" is a
// prefix of both - without picking the longest match, the broader
// "/admin" dashboard link could end up highlighted instead of "Orders".
foreach (array_keys($navItems) as $navPath) {
    if ($requestPath === $navPath || str_starts_with($requestPath, $navPath . '/')) {
        if ($currentNavPath === null || strlen($navPath) > strlen($currentNavPath)) {
            $currentNavPath = $navPath;
        }
    }
}
// Current UI language (for the <html lang="..."> attribute) and the list
// of languages an admin has turned on in Settings -> Languages (used just
// below to build the language-switcher links; see CLAUDE.md's i18n
// section for why this is enabledLanguages() and not availableLanguages()
// - only languages the shop owner actually enabled should be switchable).
$currentLang = getCurrentLanguage();
$availableLangs = getEnabledLanguages();
?>
<!doctype html>
<html lang="<?= e($currentLang) ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= isset($pageTitle) ? e($pageTitle) . ' - ' : '' ?>Admin - <?= e(SITE_NAME) ?></title>
  <link rel="stylesheet" href="<?= rtrim(SITE_URL, '/') ?>/admin/assets/css/admin.css">
</head>
<body>
<div class="admin-layout">
  <aside class="admin-sidebar">
    <div class="admin-logo"><?= e(SITE_NAME) ?> <span>Admin</span></div>
    <nav>
      <?php foreach ($navItems as $path => $item): ?>
        <?php /* Skip (don't render) any link the logged-in admin's role isn't allowed to use - e.g. a 'manager' never sees "Finance" or "Admin Accounts". */ ?>
        <?php if (!adminCan($admin, $item['capability'])) continue; ?>
        <a href="<?= rtrim(SITE_URL, '/') ?><?= e($path) ?>" class="<?= $currentNavPath === $path ? 'active' : '' ?>"><?= e($item['label']) ?></a>
      <?php endforeach; ?>
    </nav>
    <?php /* Only bother showing a language switcher when there's more than one enabled language to switch to. */ ?>
    <?php if (count($availableLangs) > 1): ?>
      <div class="admin-user" style="border-top:none;padding-top:0;">
        <div style="opacity:.7;margin-bottom:4px;"><?= e(__('nav.language')) ?></div>
        <?php /* languageSwitchUrl() rebuilds the CURRENT admin URL with just the language query/segment swapped, so switching language doesn't navigate away from the page you're on. The $i counter just inserts a " · " separator between links (none before the first one). */ ?>
        <?php $i = 0; foreach ($availableLangs as $code => $label): ?>
          <?= $i++ > 0 ? ' &middot; ' : '' ?><a href="<?= e(languageSwitchUrl($code)) ?>" style="<?= $code === $currentLang ? 'font-weight:bold;color:#fff;' : '' ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <div class="admin-user">
      <?php /* adminRoleLabel() turns the raw role value ('super_admin'/'manager') into its human-readable display label. */ ?>
      <div><?= e($admin['username'] ?? '') ?> <span style="opacity:.7;">(<?= e(adminRoleLabel($admin['role'] ?? '')) ?>)</span></div>
      <a href="<?= rtrim(SITE_URL, '/') ?>/admin/logout"><?= e(__('nav.logout')) ?></a>
      &middot; <a href="<?= rtrim(SITE_URL, '/') ?>/" target="_blank"><?= e(__('admin.view_shop')) ?></a>
    </div>
    <div class="admin-user" style="opacity:.5;font-size:11px;">shopRex v<?= e(SHOPREX_VERSION) ?></div>
  </aside>
  <main class="admin-main">
    <?php /* getFlashes() both READS and CLEARS (pull()s) the one-shot flash messages queued by Core\FlashBag - e.g. "Product saved." after a redirect. Calling it here means each flash is shown exactly once, then gone even if the page is refreshed. */ ?>
    <?php foreach (getFlashes() as $flash): ?>
      <div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endforeach; ?>
