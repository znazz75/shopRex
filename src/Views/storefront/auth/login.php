<?php /** @var string|null $error */ ?>
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
        <p class="mt-3 mb-0"><a href="<?= rtrim(SITE_URL, '/') ?>/forgot-password"><?= e(__('auth.forgot_password')) ?></a></p>
        <p class="mb-0"><?= e(__('auth.no_account_yet')) ?> <a href="<?= rtrim(SITE_URL, '/') ?>/register"><?= e(__('auth.create_one')) ?></a></p>
      </div>
    </div>
  </div>
</div>
