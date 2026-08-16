<?php
/**
 * Admin -> Customers: search/list page, plus a form to create a special
 * "test" customer account. See CLAUDE.md's "Test accounts" section: any
 * order placed while logged in as an `is_test_account` customer uses
 * TestGateway (no real payment network calls), is logged but never
 * decrements real stock, and is excluded from every financial figure -
 * this is the one and only place such an account gets created.
 *
 * @var array  $errors    Validation error messages (e.g. from the create-test-user form) to show above the page.
 * @var string $search    The current search-box value (customer name/email query), also used to keep the search box filled in after submitting.
 * @var array  $customers The (already search-filtered) customer rows - name, email, phone, order_count, test_order_count, lifetime_value, status, created_at, is_test_account.
 */
?>
<div class="page-header"><h1><?= e(__('admin.customers')) ?></h1></div>
<?php foreach ($errors as $error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endforeach; ?>

<?php /* Creates a real customers row flagged is_test_account = 1 - from then on, an admin can log into the storefront as this account (or hand its credentials to a tester) and every order it places is automatically routed through TestGateway instead of a real payment gateway. */ ?>
<div class="card">
  <h2 style="margin-top:0;"><?= e(__('admin.customers.create_test_user')) ?></h2>
  <p style="color:var(--color-muted);font-size:13px;margin-top:0;">
    <?= e(__('admin.customers.test_user_hint')) ?>
  </p>
  <form method="post" action="<?= rtrim(SITE_URL, '/') ?>/admin/customers/create-test-user" class="form-grid">
    <?= csrfField() ?>
    <div class="form-group"><label for="first_name"><?= e(__('auth.first_name')) ?></label><input type="text" id="first_name" name="first_name" required></div>
    <div class="form-group"><label for="last_name"><?= e(__('auth.last_name')) ?></label><input type="text" id="last_name" name="last_name" required></div>
    <div class="form-group"><label for="email"><?= e(__('common.email')) ?></label><input type="email" id="email" name="email" required></div>
    <div class="form-group"><label for="password"><?= e(__('common.password')) ?></label><input type="password" id="password" name="password" minlength="8" required></div>
    <div class="form-group" style="align-self:end;"><button class="btn" type="submit"><?= e(__('admin.customers.create_test_user')) ?></button></div>
  </form>
</div>

<?php /* Plain GET search form - the query lands in $_GET['q'] and the page reloads with $search/$customers already filtered server-side; no JS/AJAX involved. */ ?>
<form class="toolbar" method="get">
  <input type="text" name="q" placeholder="<?= e(__('admin.customers.search_placeholder')) ?>" value="<?= e($search) ?>">
  <button class="btn btn-secondary" type="submit"><?= e(__('common.search')) ?></button>
</form>

<table class="data-table">
  <thead><tr><th><?= e(__('admin.numbering.type_customer')) ?></th><th><?= e(__('admin.products.name')) ?></th><th><?= e(__('common.email')) ?></th><th><?= e(__('admin.customers.phone')) ?></th><th><?= e(__('admin.orders')) ?></th><th><?= e(__('admin.customers.lifetime_value')) ?></th><th><?= e(__('common.status')) ?></th><th><?= e(__('admin.customers.joined')) ?></th><th></th></tr></thead>
  <tbody>
  <?php foreach ($customers as $c): ?>
    <tr>
      <?php /* customer_number is NULL for any customer created before Admin -> Numbering existed - shown as a dash rather than blank so the column doesn't look broken. */ ?>
      <td><?= e($c['customer_number'] ?? '-') ?></td>
      <td>
        <?= e($c['first_name'] . ' ' . $c['last_name']) ?>
        <?php /* Flags accounts created via the "create test user" form above, so they're not mistaken for real customers in this list. */ ?>
        <?php if ($c['is_test_account']): ?> <span class="badge badge-processing"><?= e(__('admin.customers.test_user_badge')) ?></span><?php endif; ?>
      </td>
      <td><?= e($c['email']) ?></td>
      <td><?= e($c['phone'] ?? '-') ?></td>
      <td>
        <?= (int)$c['order_count'] ?>
        <?php /* order_count is real orders only; test_order_count is called out separately (and only shown if non-zero) so it's clear it's not mixed into the main figure. */ ?>
        <?php if ($c['test_order_count']): ?><br><small style="color:var(--color-muted);"><?= (int)$c['test_order_count'] ?> <?= e(__('admin.customers.test_lowercase')) ?></small><?php endif; ?>
      </td>
      <td><?= formatPrice((float)$c['lifetime_value']) ?></td>
      <td><span class="badge badge-<?= $c['status'] === 'active' ? 'completed' : 'cancelled' ?>"><?= e($c['status']) ?></span></td>
      <td><?= e(formatLocalDate($c['created_at'])) ?></td>
      <td><a class="btn btn-sm btn-secondary" href="<?= rtrim(SITE_URL, '/') ?>/admin/customers/<?= (int)$c['id'] ?>"><?= e(__('admin.customers.view')) ?></a></td>
    </tr>
  <?php endforeach; ?>
  <?php if (empty($customers)): ?><tr><td colspan="9"><?= e(__('admin.customers.none_found')) ?></td></tr><?php endif; ?>
  </tbody>
</table>
