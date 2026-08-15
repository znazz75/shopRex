<?php
/**
 * Admin -> Login screen (the only admin page visible while logged out).
 *
 * @var string|null $error Validation/auth-failure message to display (e.g.
 *                          "Invalid username or password"), or null when
 *                          the form is being shown fresh with no error.
 *
 * Standalone document - rendered via Controller::renderStandalone(), NOT
 * wrapped by admin/includes/header.php/footer.php (that sidebar assumes an
 * already-logged-in admin, and would try to render nav links gated by a
 * capability check against a null $admin). Direct port of
 * admin/login.php's markup, so it builds its own full <html> page here
 * instead of composing with the shared layout.
 */
// Language switcher below needs the list of admin-enabled languages, same
// as the main layout (see layout/header.php for why enabledLanguages()
// rather than availableLanguages()).
$availableLangs = getEnabledLanguages();
?>
<!doctype html>
<html lang="<?= e(getCurrentLanguage()) ?>">
<head>
  <meta charset="UTF-8">
  <title>Admin Login - <?= e(SITE_NAME) ?></title>
  <link rel="stylesheet" href="<?= rtrim(SITE_URL, '/') ?>/admin/assets/css/admin.css">
</head>
<body>
<div class="login-wrap">
  <div class="login-box">
    <h2 style="margin-top:0;"><?= e(SITE_NAME) ?> Admin</h2>
    <?php /* Only shown after a failed login attempt (wrong credentials, or CSRF token expired/mismatched). */ ?>
    <?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>
    <form method="post" action="<?= rtrim(SITE_URL, '/') ?>/admin/login">
      <?php /* Hidden CSRF token field - required so Core\Csrf can verify this POST actually originated from this form and not a forged cross-site request. */ ?>
      <?= csrfField() ?>
      <div class="form-group"><label for="username"><?= e(__('admin.username')) ?></label><input type="text" id="username" name="username" required autofocus></div>
      <div class="form-group"><label for="password"><?= e(__('common.password')) ?></label><input type="password" id="password" name="password" required></div>
      <button class="btn" type="submit" style="width:100%;"><?= e(__('admin.sign_in')) ?></button>
    </form>
    <?php /* Same "only show if there's a choice" + " · "-separator pattern as the main layout's language switcher (see layout/header.php). */ ?>
    <?php if (count($availableLangs) > 1): ?>
      <p style="text-align:center;margin-top:16px;font-size:13px;">
        <?php $i = 0; foreach ($availableLangs as $code => $label): ?>
          <?= $i++ > 0 ? ' &middot; ' : '' ?><a href="<?= e(languageSwitchUrl($code)) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
      </p>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
