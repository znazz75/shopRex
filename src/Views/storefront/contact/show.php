<?php
/**
 * @var array $errors
 * @var bool $sent
 * @var string $name
 * @var string $email
 * New in v2.00.
 */
?>
<div class="row justify-content-center">
  <div class="col-md-7">
    <h1 class="h3 mb-3"><?= e(__('contact.title')) ?></h1>

    <?php if ($sent): ?>
      <div class="alert alert-success"><?= e(__('contact.sent')) ?></div>
    <?php else: ?>
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
