<?php
/**
 * @var string|null $error
 *
 * Standalone document - rendered via Controller::renderStandalone(), NOT
 * wrapped by admin/includes/header.php/footer.php (that sidebar assumes an
 * already-logged-in admin). Direct port of admin/login.php's markup.
 */
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
    <?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>
    <form method="post" action="<?= rtrim(SITE_URL, '/') ?>/admin/login">
      <?= csrfField() ?>
      <div class="form-group"><label for="username"><?= e(__('admin.username')) ?></label><input type="text" id="username" name="username" required autofocus></div>
      <div class="form-group"><label for="password"><?= e(__('common.password')) ?></label><input type="password" id="password" name="password" required></div>
      <button class="btn" type="submit" style="width:100%;"><?= e(__('admin.sign_in')) ?></button>
    </form>
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
