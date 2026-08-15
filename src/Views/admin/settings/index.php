<?php
/**
 * Admin -> Settings: one long page of independent setting groups, each in
 * its own <div class="card">. Almost every card's fields are part of ONE
 * big form (id="settingsForm") that saves everything together to
 * SettingsAdminController's main POST handler - only the "Site URL" card
 * near the top and the "Run GDPR cleanup now" button at the bottom are
 * separate, standalone forms with their own POST targets, because those
 * two are one-off actions rather than persisted key/value settings.
 *
 * Theme-package picker note (see CLAUDE.md's "Themes" section): a
 * *storefront* layout package concept, being configured from an *admin*
 * screen - $availableThemePackages/$activeThemePackageKey come from the
 * always-storefront-flavored 'ThemeManager.storefront' container binding,
 * NOT the fixed-layout admin ThemeManager instance (which has no package
 * mechanism at all). This is unrelated to $colorThemes just below it,
 * which only recolors CSS variables within whichever layout package is
 * active - two separate concepts that happen to look like similar radio
 * button grids on this page.
 *
 * @var array       $current                 Every setting's current saved value, keyed by setting name - the source for every field's prefilled value below.
 * @var array       $fields                  Plain shop-details settings (name => label) rendered generically as text inputs in the "Shop Details" card.
 * @var array       $legalFields             Company/legal settings (name => label, e.g. company_legal_name, vat_id) for the "Company / Legal" card.
 * @var array       $paymentTextFields       Non-secret payment gateway settings (name => label, e.g. a PayPal client ID) rendered as plain text inputs.
 * @var array       $paymentSecretFields     Secret payment gateway settings (name => label, e.g. an API secret key) rendered as password inputs that never echo the actual saved value back (see the "secret_configured" placeholder logic below).
 * @var array       $availableThemePackages  Every discovered storefront theme package (key => ['name', 'description']), for the "Layout" picker.
 * @var string      $activeThemePackageKey   Which theme package is currently active.
 * @var array       $colorThemes             The color-accent presets (key => ['label', 'accent', 'navbar_bg']) from the THEMES array, for the "Frontend Colors" picker.
 * @var array       $allAvailableLangsForForm Every language that exists on disk (see I18n::availableLanguages()) - deliberately the "all", not "enabled", set, since this is the one screen that needs to show currently-disabled languages too (to let an admin re-enable one).
 * @var array       $enabledLangsForForm     The subset of $allAvailableLangsForForm currently enabled - used to pre-check the right checkboxes below.
 * @var array       $perPageOptions          Allowed values for "items per page" (e.g. '10','20','50','all'), for the product-listing page-size dropdown.
 * @var string|null $siteUrlError            Validation error for the site-URL field specifically (that form has its own error slot, separate from the main settings form), or null.
 */
$base = rtrim(SITE_URL, '/') . '/admin/settings';
?>
<div class="page-header"><h1><?= e(__('admin.settings')) ?></h1></div>

<div class="card">
  <h2 style="margin-top:0;"><?= e(__('admin.settings.site_url')) ?></h2>
  <p style="color:var(--color-muted);font-size:13px;">
    <?= e(__('admin.settings.site_url_hint', ['url' => SITE_URL])) ?>
  </p>
  <?php /* This form is standalone (its own action="/site-url", own error slot) rather than part of the big #settingsForm below - changing the site's base URL is disruptive enough (affects every generated link) that it's kept as its own explicit, isolated action. */ ?>
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

<?php /* Everything below, down to the closing </form> near the bottom, is ONE form that saves every setting card together in a single POST. */ ?>
<form method="post" action="<?= e($base) ?>" id="settingsForm">
  <?= csrfField() ?>

  <?php /* THEME PACKAGE picker (structurally different storefront layouts, e.g. a completely different homepage/header arrangement - not just colors). See this file's top docblock for why $availableThemePackages comes from the storefront-flavored ThemeManager binding even though this is an admin screen. */ ?>
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

  <?php /* COLOR ACCENT picker - a completely separate mechanism from the layout package above (see CLAUDE.md's "Themes" section): this only swaps CSS variables (accent/navbar colors) within whichever layout package is active, it can't change page structure. Each swatch pair previews the accent color and navbar background so the admin doesn't have to guess from the name alone. */ ?>
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

    <?php /* Deliberately lists ALL languages that exist on disk (not just currently-enabled ones, see this file's docblock) - this is the one screen where a currently-disabled language needs to still be selectable, to turn it back on. */ ?>
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

    <?php /* A language can be picked as default even while its checkbox above is unchecked (the "disabled" suffix just warns about that combination) - the controller is expected to reconcile/validate that combination on save, this dropdown doesn't block the selection itself. */ ?>
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

  <?php /* Shop-wide VAT on/off - this is the setting vatIsEnabled() (used throughout the admin and storefront) actually reads; turning it off hides every tax-related field/column across the whole app, not just here. */ ?>
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
      <?php /* Secret fields (API keys) are NEVER echoed back into the value="" attribute, unlike every other field on this page - that would expose the real secret in the page's HTML source to anyone who can view it. The placeholder just indicates "a value is already saved" without revealing what it is; leaving the field blank on submit is expected to mean "keep the existing secret", not "clear it" (same UX pattern as the admin-account password field). */ ?>
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
      <?php /* $perPageOptions is a list of numeric strings plus a special 'all' value - 'all' gets its own translated label ("Show all") since displaying the literal word "all" as a number wouldn't make sense; every numeric option just displays its own value. */ ?>
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
  // Guard rail: unchecking every language checkbox would leave the shop
  // with no enabled language at all (breaking every page that calls
  // I18n::current()) - rather than blocking it outright, this just asks
  // for confirmation before letting such a submission through.
  document.getElementById('settingsForm').addEventListener('submit', function (e) {
    var checked = document.querySelectorAll('input[name="enabled_languages[]"]:checked');
    if (checked.length === 0 && !confirm(<?= json_encode(__('admin.settings.enabled_languages_confirm_empty')) ?>)) {
      e.preventDefault();
    }
  });
</script>

<?php /* Manually triggers Services\GdprService's scheduled cleanup (which would otherwise only run via a cron/scheduled task) right now, on demand - see the gdpr_inactivity_months setting above for the threshold it uses. Standalone form, separate from the big settings form, since this is a one-off action rather than a setting to save. */ ?>
<form method="post" action="<?= e($base) ?>/gdpr-cleanup" style="margin-top:16px;" data-confirm="<?= e(__('admin.settings.confirm_run_cleanup')) ?>">
  <?= csrfField() ?>
  <button class="btn btn-secondary" type="submit"><?= e(__('admin.settings.run_cleanup_now')) ?></button>
</form>
