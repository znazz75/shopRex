<?php
require_once __DIR__ . '/includes/bootstrap.php';

$submitted = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $email = trim($_POST['email'] ?? '');

    $stmt = db()->prepare("SELECT * FROM customers WHERE email = ? AND status = 'active'");
    $stmt->execute([$email]);
    $customer = $stmt->fetch();

    // Always show the same "check your email" message whether or not the
    // address exists - avoids leaking which emails have accounts.
    if ($customer) {
        $token = bin2hex(random_bytes(32));
        db()->prepare('UPDATE customers SET password_reset_token = ?, password_reset_expires_at = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id = ?')
            ->execute([$token, $customer['id']]);
        Mailer::sendPasswordReset($customer, $token);
    }
    $submitted = true;
}

$pageTitle = __('auth.reset_password');
require themeTemplatePath('header.php');
?>
<div class="row justify-content-center">
  <div class="col-md-5">
    <div class="card">
      <div class="card-body p-4">
        <h1 class="h3 mb-3"><?= e(__('auth.reset_password')) ?></h1>
        <?php if ($submitted): ?>
          <div class="alert alert-success"><?= e(__('auth.reset_email_sent')) ?></div>
        <?php else: ?>
          <p class="text-secondary"><?= e(__('auth.forgot_password_prompt')) ?></p>
          <form method="post">
            <?= csrfField() ?>
            <div class="mb-3"><label class="form-label" for="email"><?= e(__('common.email')) ?></label><input class="form-control" type="email" id="email" name="email" required></div>
            <button class="btn btn-primary w-100" type="submit"><?= e(__('auth.send_reset_link')) ?></button>
          </form>
        <?php endif; ?>
        <p class="mt-3 mb-0"><a href="<?= rtrim(SITE_URL, '/') ?>/login.php"><?= e(__('auth.back_to_login')) ?></a></p>
      </div>
    </div>
  </div>
</div>

<?php require themeTemplatePath('footer.php'); ?>
