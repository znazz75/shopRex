<?php
/**
 * Storefront "confirm account deletion" page - a password re-entry gate
 * before Controllers\Storefront\AccountController actually deletes the
 * customer's account, so a hijacked/left-open session can't wipe an
 * account with a single click. Rendered at /account/delete (GET shows this
 * form; POST re-shows it with $error set on a wrong password, or performs
 * the deletion and redirects away on success). Just the body;
 * Core\Renderer::render() wraps it with the theme's header.php/footer.php.
 *
 * @var string|null $error Password-mismatch (or similar) message from a
 *                          previous failed attempt; null on a fresh GET.
 */
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
          <a class="btn btn-outline-secondary" href="<?= rtrim(SITE_URL, '/') ?>/account"><?= e(__('common.cancel')) ?></a>
        </form>
      </div>
    </div>
  </div>
</div>
