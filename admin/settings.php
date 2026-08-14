<?php
require_once __DIR__ . '/includes/bootstrap.php';
requireAdminPermission('settings');

// flat_shipping_cost/free_shipping_threshold used to live here but are
// superseded by Admin -> Shipping (weight-tier methods, sql/schema.sql's
// shipping_methods/shipping_weight_tiers) - those two settings rows are
// left in the DB unused rather than dropped (nothing reads them anymore).
$fields = [
    'shop_name'               => __('admin.settings.shop_name'),
    'shop_email'              => __('admin.settings.shop_email'),
    'currency'                => __('admin.settings.currency'),
    'currency_symbol'         => __('admin.settings.currency_symbol'),
    'bank_account_holder'     => __('admin.settings.bank_account_holder'),
    'bank_iban'                => __('admin.settings.bank_iban'),
    'bank_bic'                 => __('admin.settings.bank_bic'),
    'bank_name'                 => __('admin.settings.bank_name'),
];

// Payment gateway credentials - text fields rendered normally, secret keys
// as type="password". Empty here means "not configured via the admin" -
// the app falls back to config/config.php's constants (see paypalClientId()
// etc. in includes/PaymentGateway.php), so leaving these blank doesn't
// break an install that's only configured via env vars/constants.
$paymentTextFields = [
    'paypal_client_id'       => __('admin.settings.paypal_client_id'),
    'stripe_publishable_key' => __('admin.settings.stripe_publishable_key'),
];
$paymentSecretFields = [
    'paypal_client_secret' => __('admin.settings.paypal_client_secret'),
    'stripe_secret_key'    => __('admin.settings.stripe_secret_key'),
];

$siteUrlError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_gdpr_cleanup'])) {
    requireCsrf();
    $result = runGdprInactivityCleanup();
    setFlash('success', __('admin.settings.gdpr_cleanup_result', ['warned' => $result['warned'], 'deleted' => $result['deleted']]));
    redirect(rtrim(SITE_URL, '/') . '/admin/settings.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_site_url'])) {
    requireCsrf();
    $newSiteUrl = rtrim(trim($_POST['site_url'] ?? ''), '/');
    if ($newSiteUrl === '' || !preg_match('~^https?://~i', $newSiteUrl)) {
        $siteUrlError = __('admin.settings.site_url_required');
    } elseif (!writeInstalledConfigFile(DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS, $newSiteUrl)) {
        $siteUrlError = __('admin.settings.site_url_write_error');
    } else {
        setFlash('success', __('admin.settings.site_url_updated'));
        redirect(rtrim($newSiteUrl, '/') . '/admin/settings.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['run_gdpr_cleanup']) && !isset($_POST['save_site_url'])) {
    requireCsrf();

    $stmt = db()->prepare('UPDATE settings SET setting_value = ? WHERE setting_key = ?');
    foreach ($fields as $key => $label) {
        $stmt->execute([trim($_POST[$key] ?? ''), $key]);
    }

    // Payment keys aren't seeded rows (an untouched install should fall
    // back to config/config.php's constants, not an empty saved value -
    // see getSetting() callers in includes/PaymentGateway.php) so they
    // need an upsert rather than the UPDATE-only path above.
    $upsertStmt = db()->prepare(
        'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    foreach ($paymentTextFields as $key => $label) {
        $upsertStmt->execute([$key, trim($_POST[$key] ?? '')]);
    }
    // Secret fields never round-trip their stored value back into the
    // <input type="password"> (see the form below), so a blank submission
    // means "unchanged", not "clear it" - only overwrite when non-empty.
    foreach ($paymentSecretFields as $key => $label) {
        $val = trim($_POST[$key] ?? '');
        if ($val !== '') {
            $upsertStmt->execute([$key, $val]);
        }
    }
    $paypalMode = ($_POST['paypal_mode'] ?? '') === 'live' ? 'live' : 'sandbox';
    $upsertStmt->execute(['paypal_mode', $paypalMode]);

    $theme = array_key_exists($_POST['site_theme'] ?? '', THEMES) ? $_POST['site_theme'] : 'default';
    $stmt->execute([$theme, 'site_theme']);

    // Not a seeded settings row either (same reasoning as the payment keys above).
    $themePackage = array_key_exists($_POST['site_theme_package'] ?? '', getAvailableThemePackages()) ? $_POST['site_theme_package'] : 'default';
    $upsertStmt->execute(['site_theme_package', $themePackage]);

    $perPage = in_array($_POST['items_per_page_default'] ?? '', PER_PAGE_OPTIONS, true) ? $_POST['items_per_page_default'] : '20';
    $stmt->execute([$perPage, 'items_per_page_default']);

    // Languages: which of the discovered includes/lang/*.php files are
    // actually offered anywhere a language can be picked (storefront/admin
    // switcher, ?lang=, and the per-language tabs in Admin -> Pages/Email
    // Templates/Categories/Products -> edit) - see getEnabledLanguages()
    // in includes/i18n.php. Computed from the submitted checkboxes
    // directly rather than re-reading it back via getSetting() (which
    // caches for the rest of this request and would still return the OLD
    // value here, since it's saved a few lines below) - this is what lets
    // default_language and enabled_languages be changed in the same save
    // and stay consistent with each other.
    $allAvailableLangs = getAvailableLanguages();
    $submittedLangs = array_values(array_intersect(array_keys($allAvailableLangs), $_POST['enabled_languages'] ?? []));
    if (!$submittedLangs) {
        // Nothing checked - don't lock the site out of every language.
        $submittedLangs = array_keys($allAvailableLangs);
    }
    $lang = in_array($_POST['default_language'] ?? '', $submittedLangs, true) ? $_POST['default_language'] : $submittedLangs[0];
    $stmt->execute([$lang, 'default_language']);
    $upsertStmt->execute(['enabled_languages', implode(',', $submittedLangs)]);

    $vatEnabled = !empty($_POST['vat_enabled']) ? '1' : '0';
    $stmt->execute([$vatEnabled, 'vat_enabled']);

    foreach (['payment_method_paypal_enabled', 'payment_method_credit_card_enabled', 'payment_method_bank_transfer_enabled'] as $key) {
        $stmt->execute([!empty($_POST[$key]) ? '1' : '0', $key]);
    }

    $gdprMonths = max(4, (int)($_POST['gdpr_inactivity_months'] ?? 24));
    $stmt->execute([(string)$gdprMonths, 'gdpr_inactivity_months']);

    setFlash('success', __('admin.settings.flash_saved'));
    redirect(rtrim(SITE_URL, '/') . '/admin/settings.php');
}

$current = [];
foreach (db()->query('SELECT setting_key, setting_value FROM settings')->fetchAll() as $row) {
    $current[$row['setting_key']] = $row['setting_value'];
}

$pageTitle = __('admin.settings');
require __DIR__ . '/includes/header.php';
?>

<div class="page-header"><h1><?= e(__('admin.settings')) ?></h1></div>

<div class="card">
  <h2 style="margin-top:0;"><?= e(__('admin.settings.site_url')) ?></h2>
  <p style="color:var(--color-muted);font-size:13px;">
    <?= e(__('admin.settings.site_url_hint', ['url' => SITE_URL])) ?>
  </p>
  <?php if ($siteUrlError): ?><div class="flash flash-error"><?= e($siteUrlError) ?></div><?php endif; ?>
  <form method="post" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
    <?= csrfField() ?>
    <input type="hidden" name="save_site_url" value="1">
    <div class="form-group" style="flex:1;min-width:260px;margin-bottom:0;">
      <label for="site_url"><?= e(__('admin.settings.site_url')) ?></label>
      <input type="text" id="site_url" name="site_url" required value="<?= e(SITE_URL) ?>">
    </div>
    <button class="btn btn-sm" type="submit"><?= e(__('admin.settings.save_site_url')) ?></button>
  </form>
</div>

<form method="post" id="settingsForm">
  <?= csrfField() ?>

  <div class="card">
    <h2 style="margin-top:0;"><?= e(__('admin.settings.layout_heading')) ?></h2>
    <p style="color:var(--color-muted);font-size:13px;"><?= e(__('admin.settings.layout_hint')) ?></p>
    <div class="form-grid">
      <?php foreach (getAvailableThemePackages() as $key => $pkg): ?>
        <label style="border:1px solid var(--color-border);border-radius:8px;padding:12px;display:flex;gap:10px;align-items:flex-start;cursor:pointer;">
          <input type="radio" name="site_theme_package" value="<?= e($key) ?>" <?= getActiveThemePackageKey() === $key ? 'checked' : '' ?> style="width:auto;margin-top:3px;">
          <span>
            <strong><?= e($pkg['name']) ?></strong>
            <?php if ($pkg['description']): ?><br><span style="color:var(--color-muted);font-size:12px;"><?= e($pkg['description']) ?></span><?php endif; ?>
          </span>
        </label>
      <?php endforeach; ?>
    </div>
    <p style="color:var(--color-muted);font-size:13px;margin:10px 0 0;"><?= e(__('admin.settings.layout_add_hint')) ?></p>
  </div>

  <div class="card">
    <h2 style="margin-top:0;"><?= e(__('admin.settings.frontend_layout')) ?></h2>
    <p style="color:var(--color-muted);font-size:13px;"><?= e(__('admin.settings.frontend_layout_hint')) ?></p>
    <div class="form-grid">
      <?php foreach (THEMES as $key => $t): ?>
        <label style="border:1px solid var(--color-border);border-radius:8px;padding:12px;display:flex;gap:10px;align-items:center;cursor:pointer;">
          <input type="radio" name="site_theme" value="<?= e($key) ?>" <?= ($current['site_theme'] ?? 'default') === $key ? 'checked' : '' ?> style="width:auto;">
          <span>
            <strong><?= e($t['label']) ?></strong><br>
            <span style="display:inline-block;width:14px;height:14px;border-radius:3px;background:<?= e($t['accent']) ?>;vertical-align:middle;"></span>
            <span style="display:inline-block;width:14px;height:14px;border-radius:3px;background:<?= e($t['navbar_bg']) ?>;vertical-align:middle;margin-left:4px;"></span>
          </span>
        </label>
      <?php endforeach; ?>
    </div>
  </div>

  <?php $allAvailableLangsForForm = getAvailableLanguages(); $enabledLangsForForm = getEnabledLanguages(); ?>
  <div class="card">
    <h2 style="margin-top:0;"><?= e(__('nav.language')) ?></h2>
    <p style="color:var(--color-muted);font-size:13px;">
      <?= e(__('admin.settings.language_hint')) ?>
    </p>

    <div class="form-group">
      <label><?= e(__('admin.settings.enabled_languages')) ?></label>
      <?php foreach ($allAvailableLangsForForm as $code => $label): ?>
        <label style="font-weight:normal;display:block;margin:4px 0;">
          <input type="checkbox" name="enabled_languages[]" value="<?= e($code) ?>" style="width:auto;" <?= isset($enabledLangsForForm[$code]) ? 'checked' : '' ?>>
          <?= e($label) ?> (<?= e($code) ?>)
        </label>
      <?php endforeach; ?>
      <small style="color:var(--color-muted);"><?= e(__('admin.settings.enabled_languages_hint')) ?></small>
    </div>

    <div class="form-group" style="max-width:280px;">
      <label for="default_language"><?= e(__('admin.settings.default_language')) ?></label>
      <select id="default_language" name="default_language">
        <?php foreach ($allAvailableLangsForForm as $code => $label): ?>
          <?php /* Lists every available language, not just currently-enabled ones - so
                   checking a new language's box and picking it as the default both save
                   in one go. Picking one that isn't (also) checked above falls back to
                   the first enabled language instead, same silent-clamp style already
                   used for site_theme/items_per_page_default elsewhere in this file. */ ?>
          <option value="<?= e($code) ?>" <?= ($current['default_language'] ?? 'en') === $code ? 'selected' : '' ?>><?= e($label) ?> (<?= e($code) ?>)<?= isset($enabledLangsForForm[$code]) ? '' : ' ' . e(__('admin.settings.default_language_disabled_suffix')) ?></option>
        <?php endforeach; ?>
      </select>
      <small style="color:var(--color-muted);"><?= e(__('admin.settings.default_language_hint')) ?></small>
    </div>

    <div class="flash flash-info" style="margin-bottom:0;">
      <strong><?= e(__('admin.settings.add_language_heading')) ?></strong><br>
      <?= e(__('admin.settings.add_language_hint')) ?>
    </div>
  </div>

  <div class="card">
    <h2 style="margin-top:0;"><?= e(__('admin.settings.vat')) ?></h2>
    <div class="form-group">
      <label><input type="checkbox" name="vat_enabled" value="1" style="width:auto;" <?= ($current['vat_enabled'] ?? '1') === '1' ? 'checked' : '' ?>> <?= e(__('admin.settings.enable_vat')) ?></label>
      <p style="color:var(--color-muted);font-size:13px;margin:6px 0 0;">
        <?= e(__('admin.settings.vat_hint')) ?>
      </p>
    </div>
    <a class="btn btn-secondary btn-sm" href="tax_rates.php"><?= e(__('admin.settings.manage_tax_rates')) ?></a>
  </div>

  <div class="card">
    <h2 style="margin-top:0;"><?= e(__('admin.settings.payment_methods_heading')) ?></h2>
    <p style="color:var(--color-muted);font-size:13px;">
      <?= e(__('admin.settings.payment_methods_hint')) ?>
    </p>
    <div class="form-group">
      <label><input type="checkbox" name="payment_method_bank_transfer_enabled" value="1" style="width:auto;" <?= ($current['payment_method_bank_transfer_enabled'] ?? '1') === '1' ? 'checked' : '' ?>> <?= e(__('admin.settings.enable_bank_transfer')) ?></label>
    </div>
    <div class="form-group">
      <label><input type="checkbox" name="payment_method_paypal_enabled" value="1" style="width:auto;" <?= ($current['payment_method_paypal_enabled'] ?? '1') === '1' ? 'checked' : '' ?>> <?= e(__('admin.settings.enable_paypal')) ?></label>
    </div>
    <div class="form-group mb-0">
      <label><input type="checkbox" name="payment_method_credit_card_enabled" value="1" style="width:auto;" <?= ($current['payment_method_credit_card_enabled'] ?? '1') === '1' ? 'checked' : '' ?>> <?= e(__('admin.settings.enable_credit_card')) ?></label>
    </div>
    <p style="color:var(--color-muted);font-size:13px;margin:10px 0 0;"><?= e(__('admin.settings.invoice_note')) ?></p>
  </div>

  <div class="card">
    <h2 style="margin-top:0;"><?= e(__('admin.settings.payment_heading')) ?></h2>
    <p style="color:var(--color-muted);font-size:13px;">
      <?= e(__('admin.settings.payment_hint')) ?>
    </p>
    <div class="form-group" style="max-width:280px;">
      <label for="paypal_mode"><?= e(__('admin.settings.paypal_mode')) ?></label>
      <select id="paypal_mode" name="paypal_mode">
        <option value="sandbox" <?= ($current['paypal_mode'] ?? PAYPAL_MODE) !== 'live' ? 'selected' : '' ?>><?= e(__('admin.settings.paypal_mode_sandbox')) ?></option>
        <option value="live" <?= ($current['paypal_mode'] ?? PAYPAL_MODE) === 'live' ? 'selected' : '' ?>><?= e(__('admin.settings.paypal_mode_live')) ?></option>
      </select>
    </div>
    <div class="form-grid">
      <?php foreach ($paymentTextFields as $key => $label): ?>
        <div class="form-group">
          <label for="<?= e($key) ?>"><?= e($label) ?></label>
          <input type="text" id="<?= e($key) ?>" name="<?= e($key) ?>" autocomplete="off" value="<?= e($current[$key] ?? '') ?>">
        </div>
      <?php endforeach; ?>
      <?php foreach ($paymentSecretFields as $key => $label): ?>
        <div class="form-group">
          <label for="<?= e($key) ?>"><?= e($label) ?></label>
          <input type="password" id="<?= e($key) ?>" name="<?= e($key) ?>" autocomplete="off" placeholder="<?= !empty($current[$key]) ? e(__('admin.settings.secret_configured')) : '' ?>" value="">
        </div>
      <?php endforeach; ?>
    </div>
    <small style="color:var(--color-muted);"><?= e(__('admin.settings.payment_secret_hint')) ?></small>
  </div>

  <div class="card">
    <h2 style="margin-top:0;"><?= e(__('admin.settings.product_listings')) ?></h2>
    <div class="form-group" style="max-width:280px;">
      <label for="items_per_page_default"><?= e(__('admin.settings.default_items_per_page')) ?></label>
      <select id="items_per_page_default" name="items_per_page_default">
        <?php foreach (PER_PAGE_OPTIONS as $opt): ?>
          <option value="<?= e($opt) ?>" <?= ($current['items_per_page_default'] ?? '20') === $opt ? 'selected' : '' ?>><?= $opt === 'all' ? e(__('shop.show_all')) : $opt ?></option>
        <?php endforeach; ?>
      </select>
      <small style="color:var(--color-muted);"><?= e(__('admin.settings.items_per_page_hint')) ?></small>
    </div>
  </div>

  <div class="card">
    <h2 style="margin-top:0;"><?= e(__('admin.settings.gdpr_heading')) ?></h2>
    <p style="color:var(--color-muted);font-size:13px;">
      <?= e(__('admin.settings.gdpr_hint')) ?>
    </p>
    <div class="form-group" style="max-width:280px;">
      <label for="gdpr_inactivity_months"><?= e(__('admin.settings.gdpr_months_label')) ?></label>
      <input type="number" id="gdpr_inactivity_months" name="gdpr_inactivity_months" min="4" value="<?= e($current['gdpr_inactivity_months'] ?? '24') ?>">
    </div>
  </div>

  <div class="card">
    <h2 style="margin-top:0;"><?= e(__('admin.settings.shop_details')) ?></h2>
    <div class="form-grid">
      <?php foreach ($fields as $key => $label): ?>
        <div class="form-group">
          <label for="<?= e($key) ?>"><?= e($label) ?></label>
          <input type="text" id="<?= e($key) ?>" name="<?= e($key) ?>" value="<?= e($current[$key] ?? '') ?>">
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <button class="btn" type="submit"><?= e(__('admin.settings.save_settings')) ?></button>
</form>

<script>
  // Mirrors the server-side fallback in admin/settings.php (unchecking
  // every language re-enables all of them rather than locking the site
  // out) - this just tells the admin about it before the round-trip,
  // instead of silently overriding what they submitted.
  document.getElementById('settingsForm').addEventListener('submit', function (e) {
    var checked = document.querySelectorAll('input[name="enabled_languages[]"]:checked');
    if (checked.length === 0 && !confirm(<?= json_encode(__('admin.settings.enabled_languages_confirm_empty')) ?>)) {
      e.preventDefault();
    }
  });
</script>

<form method="post" style="margin-top:16px;" data-confirm="<?= e(__('admin.settings.confirm_run_cleanup')) ?>">
  <?= csrfField() ?>
  <input type="hidden" name="run_gdpr_cleanup" value="1">
  <button class="btn btn-secondary" type="submit"><?= e(__('admin.settings.run_cleanup_now')) ?></button>
</form>

<?php require __DIR__ . '/includes/footer.php'; ?>
