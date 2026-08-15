<?php
/**
 * Storefront contact form page. Rendered by
 * Controllers\Storefront\ContactController for both the initial GET (empty
 * form) and the re-rendered POST-with-errors case (validation failed, so
 * the controller re-renders this same view with $errors filled in and
 * $name/$email preserved so the visitor doesn't have to retype them). This
 * file is just the body; Core\Renderer::render() wraps it with the
 * theme's header.php/footer.php.
 *
 * Anti-spam is two-layered: a honeypot field below (invisible to real
 * visitors, irresistible to naive bots that fill in every field) and a
 * server-side RateLimiter (5 submissions per 60 minutes, keyed by IP+email
 * - see ContactController) that this view has no visibility into; a
 * throttled submission just comes back as one more entry in $errors.
 *
 * @var array  $errors Validation/anti-spam error messages to show above the
 *                      form (empty array on a fresh GET).
 * @var bool   $sent    True once the message was actually accepted and
 *                       emailed - swaps the whole form out for a success
 *                       banner so it can't be resubmitted by refreshing.
 * @var string $name    Previously-submitted name, redisplayed after a
 *                       validation failure so it doesn't have to be retyped.
 * @var string $email   Previously-submitted email, same reason as $name.
 * New in v2.00.
 */
?>
<div class="row justify-content-center">
  <div class="col-md-7">
    <h1 class="h3 mb-3"><?= e(__('contact.title')) ?></h1>

    <?php // Once sent successfully, show only a success banner - never the
          // form again on this response, so there's no way to double-submit
          // by refreshing (a fresh GET would start over with $sent = false). ?>
    <?php if ($sent): ?>
      <div class="alert alert-success"><?= e(__('contact.sent')) ?></div>
    <?php else: ?>
      <?php // One alert box per validation/anti-spam error returned by the
            // controller (e.g. "message required", or rate-limited). ?>
      <?php foreach ($errors as $error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endforeach; ?>
      <form method="post" action="<?= rtrim(SITE_URL, '/') ?>/contact">
        <?= csrfField() ?>
        <!-- Honeypot - hidden from real visitors via CSS, left blank; a
             bot filling every field blind trips it (see ContactController). -->
        <div style="position:absolute;left:-9999px;" aria-hidden="true">
          <label for="website">Website</label>
          <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
        </div>
        <div class="row g-3 mb-3">
          <div class="col-md-6">
            <label class="form-label" for="name"><?= e(__('contact.name')) ?></label>
            <input class="form-control" type="text" id="name" name="name" value="<?= e($name) ?>" required>
          </div>
          <div class="col-md-6">
            <label class="form-label" for="email"><?= e(__('common.email')) ?></label>
            <input class="form-control" type="email" id="email" name="email" value="<?= e($email) ?>" required>
          </div>
        </div>
        <div class="mb-3">
          <label class="form-label" for="order_number"><?= e(__('contact.order_number')) ?></label>
          <input class="form-control" type="text" id="order_number" name="order_number">
        </div>
        <div class="mb-3">
          <label class="form-label" for="subject"><?= e(__('contact.subject')) ?></label>
          <input class="form-control" type="text" id="subject" name="subject">
        </div>
        <div class="mb-3">
          <label class="form-label" for="message"><?= e(__('contact.message')) ?></label>
          <textarea class="form-control" id="message" name="message" rows="5" required></textarea>
        </div>
        <button class="btn btn-primary" type="submit"><?= e(__('contact.send')) ?></button>
      </form>
    <?php endif; ?>
  </div>
</div>
