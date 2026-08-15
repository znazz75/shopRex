<?php
/**
 * Storefront "set a new password" form - the second step of password
 * reset, reached via the emailed link (?token=...) from
 * auth/forgot_password.php. Rendered by
 * Controllers\Storefront\AuthController::showResetPassword() (GET) and
 * ::resetPassword() (POST). Just the body; Core\Renderer::render() wraps
 * it with the theme's header.php/footer.php.
 *
 * The controller re-validates $token against the DB on every load (both
 * GET and POST) - it must match a customer's password_reset_token AND not
 * be past password_reset_expires_at (a 1-hour window, set when the reset
 * email was sent). This view just reflects that lookup's outcome via
 * $customer (null = invalid/expired token) rather than trusting the token
 * itself for anything.
 *
 * @var bool       $success  True once the password has actually been
 *                            changed - shows a success message + sign-in
 *                            link instead of the form.
 * @var array|null $customer The customer the token resolved to, or null if
 *                            the token is missing/invalid/expired (shown as
 *                            an error with a link back to request a new one).
 * @var array      $errors   Validation errors (password too short,
 *                            confirmation mismatch) when re-showing the form
 *                            after a failed POST.
 * @var string     $token    The reset token from the URL/form, echoed back
 *                            into a hidden field so it survives the POST
 *                            round-trip.
 */
?>
<div class="row justify-content-center">
  <div class="col-md-5">
    <div class="card">
      <div class="card-body p-4">
        <h1 class="h3 mb-3"><?= e(__('auth.reset_password')) ?></h1>

        <?php // Three-way branch: (1) password already changed successfully
              // - show success + sign-in link, no form; (2) token doesn't
              // resolve to any customer (missing/invalid/expired) - show an
              // error with a link to request a fresh one, no form; (3)
              // token is valid and nothing's been submitted yet (or the
              // submission failed validation) - show the new-password form. ?>
        <?php if ($success): ?>
          <div class="alert alert-success"><?= e(__('auth.password_reset_success')) ?></div>
          <a class="btn btn-primary w-100" href="<?= rtrim(SITE_URL, '/') ?>/login"><?= e(__('auth.sign_in')) ?></a>
        <?php elseif (!$customer): ?>
          <div class="alert alert-danger"><?= e(__('auth.reset_link_invalid')) ?></div>
          <a href="<?= rtrim(SITE_URL, '/') ?>/forgot-password"><?= e(__('auth.reset_password')) ?></a>
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
