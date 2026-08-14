<?php /** @var array $errors */ ?>
<div class="row justify-content-center">
  <div class="col-md-6">
    <div class="card">
      <div class="card-body p-4">
        <h1 class="h3 mb-3"><?= e(__('nav.create_account')) ?></h1>
        <?php foreach ($errors as $error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endforeach; ?>
        <form method="post">
          <?= csrfField() ?>
          <div class="row g-3 mb-3">
            <div class="col-sm-6"><label class="form-label" for="first_name"><?= e(__('auth.first_name')) ?></label><input class="form-control" type="text" id="first_name" name="first_name" required></div>
            <div class="col-sm-6"><label class="form-label" for="last_name"><?= e(__('auth.last_name')) ?></label><input class="form-control" type="text" id="last_name" name="last_name" required></div>
          </div>
          <div class="mb-3"><label class="form-label" for="email"><?= e(__('common.email')) ?></label><input class="form-control" type="email" id="email" name="email" required></div>
          <div class="mb-3"><label class="form-label" for="password"><?= e(__('common.password')) ?></label><input class="form-control" type="password" id="password" name="password" minlength="8" required></div>
          <button class="btn btn-primary w-100" type="submit"><?= e(__('nav.create_account')) ?></button>
        </form>
        <p class="mt-3 mb-0"><?= e(__('auth.have_account')) ?> <a href="<?= rtrim(SITE_URL, '/') ?>/login"><?= e(__('auth.sign_in_link')) ?></a></p>
      </div>
    </div>
  </div>
</div>
