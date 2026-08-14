<?php
require_once __DIR__ . '/includes/bootstrap.php';
requireAdminPermission('customers');

$errors = [];

// ---------------------------------------------------------------
// Create a test user account
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_test_user'])) {
    requireCsrf();

    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $email = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);
    $password = $_POST['password'] ?? '';

    if (!$firstName || !$lastName) $errors[] = __('admin.customers.name_required');
    if (!$email) $errors[] = __('validation.valid_email_required');
    if (strlen($password) < 8) $errors[] = __('validation.password_min_length');

    if (!$errors) {
        $stmt = db()->prepare('SELECT id FROM customers WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $errors[] = __('validation.email_already_exists');
        }
    }

    if (!$errors) {
        $stmt = db()->prepare(
            'INSERT INTO customers (first_name, last_name, email, password_hash, is_test_account) VALUES (?, ?, ?, ?, 1)'
        );
        $stmt->execute([$firstName, $lastName, $email, password_hash($password, PASSWORD_DEFAULT)]);
        setFlash('success', __('admin.customers.test_user_created', ['email' => $email]));
        redirect(rtrim(SITE_URL, '/') . '/admin/customers.php');
    }
}

$search = trim($_GET['q'] ?? '');
$sql = "SELECT c.*,
               (SELECT COUNT(*) FROM orders o WHERE o.customer_id = c.id AND o.is_test_order = 0) AS order_count,
               (SELECT COALESCE(SUM(total),0) FROM orders o WHERE o.customer_id = c.id AND o.payment_status = 'paid' AND o.is_test_order = 0) AS lifetime_value,
               (SELECT COUNT(*) FROM orders o WHERE o.customer_id = c.id AND o.is_test_order = 1) AS test_order_count
        FROM customers c";
$params = [];
if ($search !== '') {
    $sql .= ' WHERE c.first_name LIKE ? OR c.last_name LIKE ? OR c.email LIKE ?';
    $params = ["%$search%", "%$search%", "%$search%"];
}
$sql .= ' ORDER BY c.is_test_account, c.created_at DESC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$customers = $stmt->fetchAll();

$pageTitle = __('admin.customers');
require __DIR__ . '/includes/header.php';
?>

<div class="page-header"><h1><?= e(__('admin.customers')) ?></h1></div>
<?php foreach ($errors as $error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endforeach; ?>

<div class="card">
  <h2 style="margin-top:0;"><?= e(__('admin.customers.create_test_user')) ?></h2>
  <p style="color:var(--color-muted);font-size:13px;margin-top:0;">
    <?= e(__('admin.customers.test_user_hint')) ?>
  </p>
  <form method="post" class="form-grid">
    <?= csrfField() ?>
    <input type="hidden" name="create_test_user" value="1">
    <div class="form-group"><label for="first_name"><?= e(__('auth.first_name')) ?></label><input type="text" id="first_name" name="first_name" required></div>
    <div class="form-group"><label for="last_name"><?= e(__('auth.last_name')) ?></label><input type="text" id="last_name" name="last_name" required></div>
    <div class="form-group"><label for="email"><?= e(__('common.email')) ?></label><input type="email" id="email" name="email" required></div>
    <div class="form-group"><label for="password"><?= e(__('common.password')) ?></label><input type="password" id="password" name="password" minlength="8" required></div>
    <div class="form-group" style="align-self:end;"><button class="btn" type="submit"><?= e(__('admin.customers.create_test_user')) ?></button></div>
  </form>
</div>

<form class="toolbar" method="get">
  <input type="text" name="q" placeholder="<?= e(__('admin.customers.search_placeholder')) ?>" value="<?= e($search) ?>">
  <button class="btn btn-secondary" type="submit"><?= e(__('common.search')) ?></button>
</form>

<table class="data-table">
  <thead><tr><th><?= e(__('admin.products.name')) ?></th><th><?= e(__('common.email')) ?></th><th><?= e(__('admin.customers.phone')) ?></th><th><?= e(__('admin.orders')) ?></th><th><?= e(__('admin.customers.lifetime_value')) ?></th><th><?= e(__('common.status')) ?></th><th><?= e(__('admin.customers.joined')) ?></th><th></th></tr></thead>
  <tbody>
  <?php foreach ($customers as $c): ?>
    <tr>
      <td>
        <?= e($c['first_name'] . ' ' . $c['last_name']) ?>
        <?php if ($c['is_test_account']): ?> <span class="badge badge-processing"><?= e(__('admin.customers.test_user_badge')) ?></span><?php endif; ?>
      </td>
      <td><?= e($c['email']) ?></td>
      <td><?= e($c['phone'] ?? '-') ?></td>
      <td>
        <?= (int)$c['order_count'] ?>
        <?php if ($c['test_order_count']): ?><br><small style="color:var(--color-muted);"><?= (int)$c['test_order_count'] ?> <?= e(__('admin.customers.test_lowercase')) ?></small><?php endif; ?>
      </td>
      <td><?= formatPrice((float)$c['lifetime_value']) ?></td>
      <td><span class="badge badge-<?= $c['status'] === 'active' ? 'completed' : 'cancelled' ?>"><?= e($c['status']) ?></span></td>
      <td><?= e(formatLocalDate($c['created_at'])) ?></td>
      <td><a class="btn btn-sm btn-secondary" href="customer_view.php?id=<?= (int)$c['id'] ?>"><?= e(__('admin.customers.view')) ?></a></td>
    </tr>
  <?php endforeach; ?>
  <?php if (empty($customers)): ?><tr><td colspan="8"><?= e(__('admin.customers.none_found')) ?></td></tr><?php endif; ?>
  </tbody>
</table>

<?php require __DIR__ . '/includes/footer.php'; ?>
