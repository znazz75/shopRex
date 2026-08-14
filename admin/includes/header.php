<?php
$admin = currentAdmin();
$navItems = [
    'index.php'      => ['label' => __('admin.dashboard'), 'capability' => 'dashboard'],
    'products.php'   => ['label' => __('admin.products'), 'capability' => 'products'],
    'categories.php' => ['label' => __('admin.categories'), 'capability' => 'categories'],
    'inventory.php'  => ['label' => __('admin.inventory'), 'capability' => 'inventory'],
    'pages.php'      => ['label' => __('admin.pages'), 'capability' => 'pages'],
    'menus.php'      => ['label' => __('admin.menus'), 'capability' => 'menus'],
    'email_templates.php' => ['label' => __('admin.email_templates'), 'capability' => 'settings'],
    'tax_rates.php'  => ['label' => __('admin.tax_rates'), 'capability' => 'settings'],
    'shipping.php'   => ['label' => __('admin.shipping'), 'capability' => 'shipping'],
    'orders.php'     => ['label' => __('admin.orders'), 'capability' => 'orders'],
    'finance.php'    => ['label' => __('admin.finance'), 'capability' => 'finance'],
    'customers.php'  => ['label' => __('admin.customers'), 'capability' => 'customers'],
    'admins.php'     => ['label' => __('admin.admin_accounts'), 'capability' => 'admins'],
    'settings.php'   => ['label' => __('admin.settings'), 'capability' => 'settings'],
];
$currentPage = basename($_SERVER['SCRIPT_NAME']);
$currentLang = getCurrentLanguage();
$availableLangs = getAvailableLanguages();
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
      <?php foreach ($navItems as $file => $item): ?>
        <?php if (!adminCan($admin, $item['capability'])) continue; ?>
        <a href="<?= rtrim(SITE_URL, '/') ?>/admin/<?= $file ?>" class="<?= $currentPage === $file ? 'active' : '' ?>"><?= e($item['label']) ?></a>
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
      <a href="<?= rtrim(SITE_URL, '/') ?>/admin/logout.php"><?= e(__('nav.logout')) ?></a>
      &middot; <a href="<?= rtrim(SITE_URL, '/') ?>/index.php" target="_blank"><?= e(__('admin.view_shop')) ?></a>
    </div>
  </aside>
  <main class="admin-main">
    <?php foreach (getFlashes() as $flash): ?>
      <div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endforeach; ?>
