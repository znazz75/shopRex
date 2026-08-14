<?php
require_once __DIR__ . '/includes/bootstrap.php';
requireAdminPermission('settings');

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    requireCsrf();
    $deleteId = (int)$_POST['delete_id'];
    $countStmt = db()->query('SELECT COUNT(*) FROM tax_rates');
    if ((int)$countStmt->fetchColumn() <= 1) {
        setFlash('error', __('admin.tax_rates.at_least_one_required'));
    } else {
        // Products referencing this rate fall back to no tax (tax_rate_id
        // becomes NULL) rather than silently keeping a deleted rate's number.
        db()->prepare('DELETE FROM tax_rates WHERE id = ?')->execute([$deleteId]);
        setFlash('success', __('admin.tax_rates.flash_deleted'));
    }
    redirect(rtrim(SITE_URL, '/') . '/admin/tax_rates.php');
}

$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : null;
$editRate = null;
if ($editId) {
    $stmt = db()->prepare('SELECT * FROM tax_rates WHERE id = ?');
    $stmt->execute([$editId]);
    $editRate = $stmt->fetch();
    if (!$editRate) {
        setFlash('error', __('admin.tax_rates.not_found'));
        redirect(rtrim(SITE_URL, '/') . '/admin/tax_rates.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['delete_id'])) {
    requireCsrf();

    $id = isset($_POST['id']) && $_POST['id'] !== '' ? (int)$_POST['id'] : null;
    $name = trim($_POST['name'] ?? '');
    $rate = (float)($_POST['rate'] ?? -1);
    $isDefault = !empty($_POST['is_default']);

    if ($name === '') $errors[] = __('admin.categories.name_required');
    if ($rate < 0 || $rate > 100) $errors[] = __('admin.tax_rates.rate_range');

    if (!$errors) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ($isDefault) {
                $pdo->exec('UPDATE tax_rates SET is_default = 0');
            }
            if ($id) {
                $stmt = $pdo->prepare('UPDATE tax_rates SET name=?, rate=?, is_default=? WHERE id=?');
                $stmt->execute([$name, $rate, $isDefault ? 1 : 0, $id]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO tax_rates (name, rate, is_default) VALUES (?, ?, ?)');
                $stmt->execute([$name, $rate, $isDefault ? 1 : 0]);
            }
            // Exactly one default must always exist (products with no
            // explicit tax_rate_id use it as their assumed rate).
            if ((int)$pdo->query('SELECT COUNT(*) FROM tax_rates WHERE is_default = 1')->fetchColumn() === 0) {
                $pdo->exec('UPDATE tax_rates SET is_default = 1 ORDER BY id LIMIT 1');
            }
            $pdo->commit();
            setFlash('success', __('admin.tax_rates.flash_saved'));
            redirect(rtrim(SITE_URL, '/') . '/admin/tax_rates.php');
        } catch (PDOException $e) {
            $pdo->rollBack();
            $errors[] = __('admin.tax_rates.save_error', ['message' => $e->getMessage()]);
        }
    }
    $editRate = ['id' => $id, 'name' => $name, 'rate' => $rate, 'is_default' => $isDefault ? 1 : 0];
}

$rates = db()->query('
    SELECT tr.*, (SELECT COUNT(*) FROM products p WHERE p.tax_rate_id = tr.id) AS product_count
    FROM tax_rates tr ORDER BY tr.rate DESC
')->fetchAll();

$pageTitle = __('admin.tax_rates');
require __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <h1><?= e(__('admin.tax_rates')) ?></h1>
  <a class="btn btn-secondary" href="settings.php">&larr; <?= e(__('admin.tax_rates.back_to_settings')) ?></a>
</div>
<?php if (!vatIsEnabled()): ?>
  <div class="flash flash-info"><?= e(__('admin.tax_rates.vat_disabled_notice')) ?></div>
<?php endif; ?>
<?php foreach ($errors as $error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endforeach; ?>

<div class="card">
  <h2 style="margin-top:0;"><?= !empty($editRate['id']) ? e(__('admin.tax_rates.edit_title')) : e(__('admin.tax_rates.add_title')) ?></h2>
  <form method="post" class="form-grid">
    <?= csrfField() ?>
    <input type="hidden" name="id" value="<?= e($editRate['id'] ?? '') ?>">
    <div class="form-group"><label for="name"><?= e(__('admin.products.name')) ?></label><input type="text" id="name" name="name" required placeholder="<?= e(__('admin.tax_rates.name_placeholder')) ?>" value="<?= e($editRate['name'] ?? '') ?>"></div>
    <div class="form-group"><label for="rate"><?= e(__('admin.tax_rates.rate_percent')) ?></label><input type="number" step="0.01" min="0" max="100" id="rate" name="rate" required value="<?= e($editRate['rate'] ?? '') ?>"></div>
    <div class="form-group" style="align-self:end;">
      <label><input type="checkbox" name="is_default" value="1" style="width:auto;" <?= !empty($editRate['is_default']) ? 'checked' : '' ?>> <?= e(__('admin.tax_rates.default_rate')) ?></label>
    </div>
    <div class="form-group" style="align-self:end;"><button class="btn" type="submit"><?= e(__('common.save')) ?></button></div>
  </form>
</div>

<table class="data-table">
  <thead><tr><th><?= e(__('admin.products.name')) ?></th><th><?= e(__('admin.tax_rates.rate')) ?></th><th><?= e(__('admin.tax_rates.default_col')) ?></th><th><?= e(__('admin.tax_rates.products_using')) ?></th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rates as $r): ?>
    <tr>
      <td><?= e($r['name']) ?></td>
      <td><?= e($r['rate']) ?>%</td>
      <td><?= $r['is_default'] ? '<span class="badge badge-completed">' . e(__('admin.tax_rates.default_col')) . '</span>' : '' ?></td>
      <td><?= (int)$r['product_count'] ?></td>
      <td>
        <a class="btn btn-sm btn-secondary" href="tax_rates.php?edit=<?= (int)$r['id'] ?>"><?= e(__('common.edit')) ?></a>
        <form method="post" style="display:inline;" data-confirm="<?= e(__('admin.tax_rates.confirm_delete', ['name' => $r['name']])) ?>">
          <?= csrfField() ?>
          <input type="hidden" name="delete_id" value="<?= (int)$r['id'] ?>">
          <button class="btn btn-sm btn-danger" type="submit"><?= e(__('common.delete')) ?></button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (empty($rates)): ?><tr><td colspan="5"><?= e(__('admin.tax_rates.none_yet')) ?></td></tr><?php endif; ?>
  </tbody>
</table>

<?php require __DIR__ . '/includes/footer.php'; ?>
