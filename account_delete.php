<?php
require_once __DIR__ . '/includes/bootstrap.php';
requireCustomerLogin();
$customer = currentCustomer();

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $password = $_POST['password'] ?? '';

    if (!password_verify($password, $customer['password_hash'])) {
        $error = __('account.delete_wrong_password');
    } else {
        GdprTools::deleteCustomer($customer['id']);
        unset($_SESSION['customer_id']);
        session_regenerate_id(true);
        setFlash('success', __('account.delete_success'));
        redirect(rtrim(SITE_URL, '/') . '/index.php');
    }
}

$pageTitle = __('account.delete_confirm_title');
require themeTemplatePath('header.php');
?>
<div class="row justify-content-center">
  <div class="col-md-6">
    <div class="card border-danger">
      <div class="card-body p-4">
        <h1 class="h4 mb-3"><?= e(__('account.delete_confirm_title')) ?></h1>
        <div class="alert alert-warning"><?= e(__('account.delete_confirm_text')) ?></div>
        <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
        <form method="post">
          <?= csrfField() ?>
          <div class="mb-3">
            <label class="form-label" for="password"><?= e(__('account.delete_confirm_password')) ?></label>
            <input class="form-control" type="password" id="password" name="password" required>
          </div>
          <button class="btn btn-danger" type="submit"><?= e(__('account.delete_account')) ?></button>
          <a class="btn btn-outline-secondary" href="<?= rtrim(SITE_URL, '/') ?>/account.php"><?= e(__('common.cancel')) ?></a>
        </form>
      </div>
    </div>
  </div>
</div>

<?php require themeTemplatePath('footer.php'); ?>
