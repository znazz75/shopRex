<?php
/**
 * Storefront "forgot password" request form - the first step of password
 * reset, where a customer types in their email to receive a reset link.
 * Rendered by Controllers\Storefront\AuthController::showForgotPassword()
 * (GET, $submitted = false) and ::forgotPassword() (POST, $submitted =
 * true once handled). Just the body; Core\Renderer::render() wraps it
 * with the theme's header.php/footer.php.
 *
 * SECURITY: the controller always shows the same "check your email"
 * success message after a POST, whether or not that email actually
 * belongs to an account - this view has no way to tell the difference
 * either, by design, so it can't be used to enumerate registered emails.
 * The request is also rate-limited server-side regardless of outcome.
 *
 * @var bool $submitted True after a POST has been handled (always shows
 *                       the "check your email" message then, regardless of
 *                       whether an email was actually sent); false on the
 *                       initial GET (shows the request form).
 */
?>
<div class="row justify-content-center">
  <div class="col-md-5">
    <div class="card">
      <div class="card-body p-4">
        <h1 class="h3 mb-3"><?= e(__('auth.reset_password')) ?></h1>
        <?php // Once submitted, always show the generic "check your email"
              // success message instead of the form - never reveals whether
              // the address was actually found (see security note above). ?>
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
        <p class="mt-3 mb-0"><a href="<?= rtrim(SITE_URL, '/') ?>/login"><?= e(__('auth.back_to_login')) ?></a></p>
      </div>
    </div>
  </div>
</div>
