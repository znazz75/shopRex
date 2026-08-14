<?php
/**
 * @var array $current
 * @var array $fields
 * @var array $legalFields
 * @var array $paymentTextFields
 * @var array $paymentSecretFields
 * @var array $availableThemePackages
 * @var string $activeThemePackageKey
 * @var array $colorThemes
 * @var array $allAvailableLangsForForm
 * @var array $enabledLangsForForm
 * @var array $perPageOptions
 * @var string|null $siteUrlError
 */
$base = rtrim(SITE_URL, '/') . '/admin/settings';
?>
<div class="page-header"><h1><?= e(__('admin.settings')) ?></h1></div>

<div class="card">
  <h2 style="margin-top:0;"><?= e(__('admin.settings.site_url')) ?></h2>
  <p style="color:var(--color-muted);font-size:13px;">
    <?= e(__('admin.settings.site_url_hint', ['url' => SITE_URL])) ?>
  </p>
  <?php if ($siteUrlError): ?><div class="flash flash-error"><?= e($siteUrlError) ?></div><?php endif; ?>
  <form method="post" action="<?= e($base) ?>/site-url" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
    <?= csrfField() ?>
    <div class="form-group" style="flex:1;min-width:260px;margin-bottom:0;">
      <label for="site_url"><?= e(__('admin.settings.site_url')) ?></label>
      <input type="text" id="site_url" name="site_url" required value="<?= e(SITE_URL) ?>">
    </div>
    <button class="btn btn-sm" type="submit"><?= e(__('admin.settings.save_site_url')) ?></button>
  </form>
</div>

<form method="post" action="<?= e($base) ?>" id="settingsForm">
  <?= csrfField() ?>

  <div class="card">
    <h2 style="margin-top:0;"><?= e(__('admin.settings.layout_heading')) ?></h2>
    <p style="color:var(--color-muted);font-size:13px;"><?= e(__('admin.settings.layout_hint')) ?></p>
    <div class="form-grid">
      <?php foreach ($availableThemePackages as $key => $pkg): ?>
        <label style="border:1px solid var(--color-border);border-radius:8px;padding:12px;display:flex;gap:10px;align-items:flex-start;cursor:pointer;">
          <input type="radio" name="site_theme_package" value="<?= e($key) ?>" <?= $activeThemePackageKey === $key ? 'checked' : '' ?> style="width:auto;margin-top:3px;">
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
      <?php foreach ($colorThemes as $key => $t): ?>
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
    <a class="btn btn-secondary btn-sm" href="<?= rtrim(SITE_URL, '/') ?>/admin/tax-rates"><?= e(__('admin.settings.manage_tax_rates')) ?></a>
  </div>

  <div class="card">
    <h2 style="margin-top:0;"><?= e(__('admin.settings.company_legal_heading')) ?></h2>
    <p style="color:var(--color-muted);font-size:13px;">
      <?= e(__('admin.settings.company_legal_hint')) ?>
    </p>
    <div class="form-grid">
      <?php foreach ($legalFields as $key => $label): ?>
        <div class="form-group">
          <label for="<?= e($key) ?>"><?= e($label) ?></label>
          <input type="text" id="<?= e($key) ?>" name="<?= e($key) ?>" value="<?= e($current[$key] ?? '') ?>">
        </div>
      <?php endforeach; ?>
    </div>
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
        <?php foreach ($perPageOptions as $opt): ?>
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
  document.getElementById('settingsForm').addEventListener('submit', function (e) {
    var checked = document.querySelectorAll('input[name="enabled_languages[]"]:checked');
    if (checked.length === 0 && !confirm(<?= json_encode(__('admin.settings.enabled_languages_confirm_empty')) ?>)) {
      e.preventDefault();
    }
  });
</script>

<form method="post" action="<?= e($base) ?>/gdpr-cleanup" style="margin-top:16px;" data-confirm="<?= e(__('admin.settings.confirm_run_cleanup')) ?>">
  <?= csrfField() ?>
  <button class="btn btn-secondary" type="submit"><?= e(__('admin.settings.run_cleanup_now')) ?></button>
</form>
