<?php
require_once __DIR__ . '/includes/bootstrap.php';

$token = $_GET['token'] ?? $_POST['token'] ?? '';
$errors = [];
$success = false;

$stmt = db()->prepare(
    "SELECT * FROM customers WHERE password_reset_token = ? AND password_reset_expires_at > NOW() AND status = 'active'"
);
$stmt->execute([$token]);
$customer = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    if (!$customer) {
        $errors[] = __('auth.reset_link_invalid');
    } else {
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['password_confirm'] ?? '';
        if (strlen($password) < 8) $errors[] = __('validation.password_min_length');
        if ($password !== $confirm) $errors[] = __('validation.passwords_mismatch');

        if (!$errors) {
            db()->prepare('UPDATE customers SET password_hash = ?, password_reset_token = NULL, password_reset_expires_at = NULL WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $customer['id']]);
            $success = true;
        }
    }
}

$pageTitle = __('auth.reset_password');
require themeTemplatePath('header.php');
?>
<div class="row justify-content-center">
  <div class="col-md-5">
    <div class="card">
      <div class="card-body p-4">
        <h1 class="h3 mb-3"><?= e(__('auth.reset_password')) ?></h1>

        <?php if ($success): ?>
          <div class="alert alert-success"><?= e(__('auth.password_reset_success')) ?></div>
          <a class="btn btn-primary w-100" href="<?= rtrim(SITE_URL, '/') ?>/login.php"><?= e(__('auth.sign_in')) ?></a>
        <?php elseif (!$customer): ?>
          <div class="alert alert-danger"><?= e(__('auth.reset_link_invalid')) ?></div>
          <a href="<?= rtrim(SITE_URL, '/') ?>/forgot_password.php"><?= e(__('auth.reset_password')) ?></a>
        <?php else: ?>
          <?php foreach ($errors as $error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endforeach; ?>
          <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="token" value="<?= e($token) ?>">
            <div class="mb-3"><label class="form-label" for="password"><?= e(__('auth.new_password')) ?></label><input class="form-control" type="password" id="password" name="password" minlength="8" required></div>
            <div class="mb-3"><label class="form-label" for="password_confirm"><?= e(__('auth.confirm_password')) ?></label><input class="form-control" type="password" id="password_confirm" name="password_confirm" minlength="8" required></div>
            <button class="btn btn-primary w-100" type="submit"><?= e(__('auth.reset_password')) ?></button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php require themeTemplatePath('footer.php'); ?>
