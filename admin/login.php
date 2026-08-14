<?php
require_once __DIR__ . '/includes/bootstrap.php';

if (currentAdmin()) {
    redirect(rtrim(SITE_URL, '/') . '/admin/index.php');
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $rateLimitId = loginAttemptIdentifier($username);

    if (isRateLimited($rateLimitId)) {
        $error = __('auth.too_many_attempts');
    } else {
        $stmt = db()->prepare("SELECT * FROM admin_users WHERE username = ? AND status = 'active'");
        $stmt->execute([$username]);
        $admin = $stmt->fetch();

        if ($admin && password_verify($password, $admin['password_hash'])) {
            clearLoginAttempts($rateLimitId);
            regenerateSession();
            $_SESSION['admin_id'] = (int)$admin['id'];
            db()->prepare('UPDATE admin_users SET last_login = NOW() WHERE id = ?')->execute([$admin['id']]);
            redirect(rtrim(SITE_URL, '/') . '/admin/index.php');
        }
        recordFailedLoginAttempt($rateLimitId);
        $error = __('auth.invalid_credentials');
    }
}
$availableLangs = getAvailableLanguages();
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
    <form method="post">
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
