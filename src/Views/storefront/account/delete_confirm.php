<?php /** @var string|null $error */ ?>
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
          <a class="btn btn-outline-secondary" href="<?= rtrim(SITE_URL, '/') ?>/account"><?= e(__('common.cancel')) ?></a>
        </form>
      </div>
    </div>
  </div>
</div>
