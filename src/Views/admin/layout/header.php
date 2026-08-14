<?php
/**
 * Admin's one fixed layout (Core\ThemeManager has no package/override
 * mechanism on the admin side - see src/container.php's binding). Expects
 * $pageTitle to optionally be set before include - see
 * Core\Renderer::render(), which requires this file directly into the
 * calling controller's data scope.
 */
$admin = currentAdmin();
$navItems = [
    '/admin'               => ['label' => __('admin.dashboard'), 'capability' => 'dashboard'],
    '/admin/products'      => ['label' => __('admin.products'), 'capability' => 'products'],
    '/admin/categories'    => ['label' => __('admin.categories'), 'capability' => 'categories'],
    '/admin/inventory'     => ['label' => __('admin.inventory'), 'capability' => 'inventory'],
    '/admin/pages'         => ['label' => __('admin.pages'), 'capability' => 'pages'],
    '/admin/menus'         => ['label' => __('admin.menus'), 'capability' => 'menus'],
    '/admin/email-templates' => ['label' => __('admin.email_templates'), 'capability' => 'settings'],
    '/admin/tax-rates'     => ['label' => __('admin.tax_rates'), 'capability' => 'settings'],
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
$requestPath = currentPath();
$currentNavPath = null;
foreach (array_keys($navItems) as $navPath) {
    if ($requestPath === $navPath || str_starts_with($requestPath, $navPath . '/')) {
        if ($currentNavPath === null || strlen($navPath) > strlen($currentNavPath)) {
            $currentNavPath = $navPath;
        }
    }
}
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
        <?php if (!adminCan($admin, $item['capability'])) continue; ?>
        <a href="<?= rtrim(SITE_URL, '/') ?><?= e($path) ?>" class="<?= $currentNavPath === $path ? 'active' : '' ?>"><?= e($item['label']) ?></a>
      <?php endforeach; ?>
    </nav>
    <?php if (count($availableLangs) > 1): ?>
      <div class="admin-user" style="border-top:none;padding-top:0;">
        <div style="opacity:.7;margin-bottom:4px;"><?= e(__('nav.language')) ?></div>
        <?php $i = 0; foreach ($availableLangs as $code => $label): ?>
          <?= $i++ > 0 ? ' &middot; ' : '' ?><a href="<?= e(languageSwitchUrl($code)) ?>" style="<?= $code === $currentLang ? 'font-weight:bold;color:#fff;' : '' ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <div class="admin-user">
      <div><?= e($admin['username'] ?? '') ?> <span style="opacity:.7;">(<?= e(adminRoleLabel($admin['role'] ?? '')) ?>)</span></div>
      <a href="<?= rtrim(SITE_URL, '/') ?>/admin/logout"><?= e(__('nav.logout')) ?></a>
      &middot; <a href="<?= rtrim(SITE_URL, '/') ?>/" target="_blank"><?= e(__('admin.view_shop')) ?></a>
    </div>
    <div class="admin-user" style="opacity:.5;font-size:11px;">shopRex v<?= e(SHOPREX_VERSION) ?></div>
  </aside>
  <main class="admin-main">
    <?php foreach (getFlashes() as $flash): ?>
      <div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endforeach; ?>
