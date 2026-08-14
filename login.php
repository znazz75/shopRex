<?php
require_once __DIR__ . '/includes/bootstrap.php';

if (currentCustomer()) {
    redirect(rtrim(SITE_URL, '/') . '/account.php');
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = db()->prepare("SELECT * FROM customers WHERE email = ? AND status = 'active'");
    $stmt->execute([$email]);
    $customer = $stmt->fetch();

    if ($customer && password_verify($password, $customer['password_hash'])) {
        regenerateSession();
        $_SESSION['customer_id'] = (int)$customer['id'];
        db()->prepare('UPDATE customers SET last_login_at = NOW(), deletion_warning_sent_at = NULL WHERE id = ?')->execute([$customer['id']]);
        redirect(rtrim(SITE_URL, '/') . '/account.php');
    }
    $error = __('auth.invalid_credentials');
}

$pageTitle = __('auth.sign_in');
require themeTemplatePath('header.php');
?>
<div class="row justify-content-center">
  <div class="col-md-5">
    <div class="card">
      <div class="card-body p-4">
        <h1 class="h3 mb-3"><?= e(__('auth.sign_in')) ?></h1>
        <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
        <form method="post">
          <?= csrfField() ?>
          <div class="mb-3"><label class="form-label" for="email"><?= e(__('common.email')) ?></label><input class="form-control" type="email" id="email" name="email" required></div>
          <div class="mb-3">
            <label class="form-label" for="password"><?= e(__('common.password')) ?></label>
            <input class="form-control" type="password" id="password" name="password" required>
          </div>
          <button class="btn btn-primary w-100" type="submit"><?= e(__('auth.sign_in')) ?></button>
        </form>
        <p class="mt-3 mb-0"><a href="<?= rtrim(SITE_URL, '/') ?>/forgot_password.php"><?= e(__('auth.forgot_password')) ?></a></p>
        <p class="mb-0"><?= e(__('auth.no_account_yet')) ?> <a href="<?= rtrim(SITE_URL, '/') ?>/register.php"><?= e(__('auth.create_one')) ?></a></p>
      </div>
    </div>
  </div>
</div>

<?php require themeTemplatePath('footer.php'); ?>
