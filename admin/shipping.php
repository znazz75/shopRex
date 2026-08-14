<?php
require_once __DIR__ . '/includes/bootstrap.php';
requireAdminPermission('shipping');

$errors = [];

// ---------------------------------------------------------------
// Delete
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    requireCsrf();
    // Past orders keep their shipping_method_name snapshot (see
    // sql/schema.sql orders.shipping_method_name) - deleting a method never
    // corrupts old order history, it just stops being offered at checkout.
    db()->prepare('DELETE FROM shipping_methods WHERE id = ?')->execute([(int)$_POST['delete_id']]);
    setFlash('success', __('admin.shipping.flash_deleted'));
    redirect(rtrim(SITE_URL, '/') . '/admin/shipping.php');
}

// ---------------------------------------------------------------
// Create / update
// ---------------------------------------------------------------
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : null;
$editMethod = null;
$editTiers = [];
if ($editId) {
    $stmt = db()->prepare('SELECT * FROM shipping_methods WHERE id = ?');
    $stmt->execute([$editId]);
    $editMethod = $stmt->fetch();
    if (!$editMethod) {
        setFlash('error', __('admin.shipping.not_found'));
        redirect(rtrim(SITE_URL, '/') . '/admin/shipping.php');
    }
    $tierStmt = db()->prepare('SELECT * FROM shipping_weight_tiers WHERE shipping_method_id = ? ORDER BY up_to_weight_kg ASC');
    $tierStmt->execute([$editId]);
    $editTiers = $tierStmt->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['delete_id'])) {
    requireCsrf();

    $id = isset($_POST['id']) && $_POST['id'] !== '' ? (int)$_POST['id'] : null;
    $name = trim($_POST['name'] ?? '');
    $isActive = !empty($_POST['is_active']) ? 1 : 0;
    $sortOrder = (int)($_POST['sort_order'] ?? 0);
    $freeMinValue = trim($_POST['free_shipping_min_order_value'] ?? '') !== '' ? (float)$_POST['free_shipping_min_order_value'] : null;
    $freeMinQty = trim($_POST['free_shipping_min_quantity'] ?? '') !== '' ? max(1, (int)$_POST['free_shipping_min_quantity']) : null;
    $extraStepKg = trim($_POST['extra_weight_step_kg'] ?? '') !== '' ? (float)$_POST['extra_weight_step_kg'] : null;
    $extraStepPrice = trim($_POST['extra_weight_step_price'] ?? '') !== '' ? (float)$_POST['extra_weight_step_price'] : null;

    $tierUpTo = $_POST['tier_up_to'] ?? [];
    $tierPrice = $_POST['tier_price'] ?? [];
    $tiers = [];
    foreach ($tierUpTo as $i => $upTo) {
        $upTo = trim($upTo);
        $price = trim($tierPrice[$i] ?? '');
        if ($upTo === '' || $price === '') {
            continue;
        }
        $tiers[] = ['up_to_weight_kg' => (float)$upTo, 'price' => (float)$price];
    }
    usort($tiers, fn($a, $b) => $a['up_to_weight_kg'] <=> $b['up_to_weight_kg']);

    if ($name === '') {
        $errors[] = __('admin.shipping.name_required');
    }
    if (empty($tiers)) {
        $errors[] = __('admin.shipping.tier_required');
    }
    if (($extraStepKg !== null) !== ($extraStepPrice !== null)) {
        $errors[] = __('admin.shipping.extra_step_pair_required');
    }
    if ($extraStepKg !== null && $extraStepKg <= 0) {
        $errors[] = __('admin.shipping.extra_step_kg_positive');
    }

    if (!$errors) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ($id) {
                $stmt = $pdo->prepare(
                    'UPDATE shipping_methods SET name=?, is_active=?, sort_order=?, free_shipping_min_order_value=?, free_shipping_min_quantity=?, extra_weight_step_kg=?, extra_weight_step_price=? WHERE id=?'
                );
                $stmt->execute([$name, $isActive, $sortOrder, $freeMinValue, $freeMinQty, $extraStepKg, $extraStepPrice, $id]);
                $methodId = $id;
                $pdo->prepare('DELETE FROM shipping_weight_tiers WHERE shipping_method_id = ?')->execute([$methodId]);
                setFlash('success', __('admin.shipping.flash_updated'));
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO shipping_methods (name, is_active, sort_order, free_shipping_min_order_value, free_shipping_min_quantity, extra_weight_step_kg, extra_weight_step_price) VALUES (?,?,?,?,?,?,?)'
                );
                $stmt->execute([$name, $isActive, $sortOrder, $freeMinValue, $freeMinQty, $extraStepKg, $extraStepPrice]);
                $methodId = (int)$pdo->lastInsertId();
                setFlash('success', __('admin.shipping.flash_added'));
            }
            $tierStmt = $pdo->prepare('INSERT INTO shipping_weight_tiers (shipping_method_id, up_to_weight_kg, price, sort_order) VALUES (?,?,?,?)');
            foreach ($tiers as $i => $tier) {
                $tierStmt->execute([$methodId, $tier['up_to_weight_kg'], $tier['price'], $i]);
            }
            $pdo->commit();
            redirect(rtrim(SITE_URL, '/') . '/admin/shipping.php');
        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors[] = __('admin.shipping.save_error', ['message' => $e->getMessage()]);
        }
    }
    $editMethod = [
        'id' => $id, 'name' => $name, 'is_active' => $isActive, 'sort_order' => $sortOrder,
        'free_shipping_min_order_value' => $freeMinValue, 'free_shipping_min_quantity' => $freeMinQty,
        'extra_weight_step_kg' => $extraStepKg, 'extra_weight_step_price' => $extraStepPrice,
    ];
    $editTiers = array_map(fn($t) => ['up_to_weight_kg' => $t['up_to_weight_kg'], 'price' => $t['price']], $tiers);
}

$methods = db()->query('SELECT * FROM shipping_methods ORDER BY sort_order, id')->fetchAll();
foreach ($methods as &$m) {
    $tierCountStmt = db()->prepare('SELECT COUNT(*) FROM shipping_weight_tiers WHERE shipping_method_id = ?');
    $tierCountStmt->execute([$m['id']]);
    $m['tier_count'] = (int)$tierCountStmt->fetchColumn();
}
unset($m);

// Always show at least one blank tier row for a brand-new method.
if (empty($editTiers)) {
    $editTiers = [['up_to_weight_kg' => '', 'price' => '']];
}

$pageTitle = __('admin.shipping');
require __DIR__ . '/includes/header.php';
?>

<div class="page-header"><h1><?= e(__('admin.shipping')) ?></h1></div>
<?php foreach ($errors as $error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endforeach; ?>

<div class="card">
  <h2 style="margin-top:0;"><?= !empty($editMethod['id']) ? e(__('admin.shipping.edit_title')) : e(__('admin.shipping.add_title')) ?></h2>
  <form method="post" id="shippingForm">
    <?= csrfField() ?>
    <input type="hidden" name="id" value="<?= e($editMethod['id'] ?? '') ?>">
    <div class="form-grid">
      <div class="form-group"><label for="name"><?= e(__('admin.shipping.method_name')) ?></label><input type="text" id="name" name="name" required placeholder="<?= e(__('admin.shipping.method_name_placeholder')) ?>" value="<?= e($editMethod['name'] ?? '') ?>"></div>
      <div class="form-group"><label for="sort_order"><?= e(__('admin.shipping.sort_order')) ?></label><input type="number" id="sort_order" name="sort_order" value="<?= e($editMethod['sort_order'] ?? '0') ?>"></div>
    </div>
    <div class="form-group">
      <label><input type="checkbox" name="is_active" value="1" style="width:auto;" <?= ($editMethod['is_active'] ?? 1) ? 'checked' : '' ?>> <?= e(__('common.active')) ?></label>
    </div>

    <fieldset>
      <legend><?= e(__('admin.shipping.weight_tiers')) ?></legend>
      <p style="color:var(--color-muted);font-size:13px;margin-top:0;"><?= e(__('admin.shipping.weight_tiers_hint')) ?></p>
      <div id="tierRows">
        <?php foreach ($editTiers as $tier): ?>
          <div class="form-grid tier-row" style="align-items:end;">
            <div class="form-group"><label><?= e(__('admin.shipping.up_to_weight')) ?></label><input type="number" step="0.01" min="0" name="tier_up_to[]" value="<?= e($tier['up_to_weight_kg']) ?>"></div>
            <div class="form-group"><label><?= e(__('admin.shipping.tier_price')) ?></label><input type="number" step="0.01" min="0" name="tier_price[]" value="<?= e($tier['price']) ?>"></div>
            <div class="form-group"><button type="button" class="btn btn-sm btn-secondary" onclick="removeTierRow(this)"><?= e(__('common.delete')) ?></button></div>
          </div>
        <?php endforeach; ?>
      </div>
      <button type="button" class="btn btn-sm btn-secondary" onclick="addTierRow()"><?= e(__('admin.shipping.add_tier')) ?></button>
    </fieldset>

    <fieldset>
      <legend><?= e(__('admin.shipping.extra_step_legend')) ?></legend>
      <p style="color:var(--color-muted);font-size:13px;margin-top:0;"><?= e(__('admin.shipping.extra_step_hint')) ?></p>
      <div class="form-grid">
        <div class="form-group"><label for="extra_weight_step_kg"><?= e(__('admin.shipping.extra_step_kg')) ?></label><input type="number" step="0.01" min="0" id="extra_weight_step_kg" name="extra_weight_step_kg" value="<?= e($editMethod['extra_weight_step_kg'] ?? '') ?>"></div>
        <div class="form-group"><label for="extra_weight_step_price"><?= e(__('admin.shipping.extra_step_price')) ?></label><input type="number" step="0.01" min="0" id="extra_weight_step_price" name="extra_weight_step_price" value="<?= e($editMethod['extra_weight_step_price'] ?? '') ?>"></div>
      </div>
    </fieldset>

    <fieldset>
      <legend><?= e(__('admin.shipping.free_shipping_legend')) ?></legend>
      <p style="color:var(--color-muted);font-size:13px;margin-top:0;"><?= e(__('admin.shipping.free_shipping_hint')) ?></p>
      <div class="form-grid">
        <div class="form-group"><label for="free_shipping_min_order_value"><?= e(__('admin.shipping.free_min_value')) ?></label><input type="number" step="0.01" min="0" id="free_shipping_min_order_value" name="free_shipping_min_order_value" value="<?= e($editMethod['free_shipping_min_order_value'] ?? '') ?>"></div>
        <div class="form-group"><label for="free_shipping_min_quantity"><?= e(__('admin.shipping.free_min_qty')) ?></label><input type="number" min="1" id="free_shipping_min_quantity" name="free_shipping_min_quantity" value="<?= e($editMethod['free_shipping_min_quantity'] ?? '') ?>"></div>
      </div>
    </fieldset>

    <button class="btn" type="submit"><?= e(__('admin.shipping.save')) ?></button>
    <?php if (!empty($editMethod['id'])): ?><a class="btn btn-secondary" href="shipping.php"><?= e(__('common.cancel')) ?></a><?php endif; ?>
  </form>
</div>

<table class="data-table">
  <thead><tr><th><?= e(__('admin.shipping.method_name')) ?></th><th><?= e(__('common.status')) ?></th><th><?= e(__('admin.shipping.weight_tiers')) ?></th><th><?= e(__('admin.shipping.free_shipping_legend')) ?></th><th></th></tr></thead>
  <tbody>
  <?php foreach ($methods as $m): ?>
    <tr>
      <td><?= e($m['name']) ?></td>
      <td><span class="badge badge-<?= $m['is_active'] ? 'completed' : 'cancelled' ?>"><?= $m['is_active'] ? e(__('common.active')) : e(__('admin.admins.disabled')) ?></span></td>
      <td><?= (int)$m['tier_count'] ?></td>
      <td>
        <?php if ($m['free_shipping_min_order_value'] !== null): ?>
          <?= e(__('admin.shipping.free_summary_value', ['value' => formatPrice((float)$m['free_shipping_min_order_value'])])) ?><br>
        <?php endif; ?>
        <?php if ($m['free_shipping_min_quantity'] !== null): ?>
          <?= e(__('admin.shipping.free_summary_qty', ['n' => $m['free_shipping_min_quantity']])) ?>
        <?php endif; ?>
        <?php if ($m['free_shipping_min_order_value'] === null && $m['free_shipping_min_quantity'] === null): ?>&mdash;<?php endif; ?>
      </td>
      <td>
        <a class="btn btn-sm btn-secondary" href="shipping.php?edit=<?= (int)$m['id'] ?>"><?= e(__('common.edit')) ?></a>
        <form method="post" style="display:inline;" data-confirm="<?= e(__('admin.shipping.confirm_delete', ['name' => $m['name']])) ?>">
          <?= csrfField() ?>
          <input type="hidden" name="delete_id" value="<?= (int)$m['id'] ?>">
          <button class="btn btn-sm btn-danger" type="submit"><?= e(__('common.delete')) ?></button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (empty($methods)): ?><tr><td colspan="5"><?= e(__('admin.shipping.none_yet')) ?></td></tr><?php endif; ?>
  </tbody>
</table>

<script>
  function addTierRow() {
    var container = document.getElementById('tierRows');
    var row = document.createElement('div');
    row.className = 'form-grid tier-row';
    row.style.alignItems = 'end';
    row.innerHTML =
      '<div class="form-group"><label><?= e(__('admin.shipping.up_to_weight')) ?></label><input type="number" step="0.01" min="0" name="tier_up_to[]"></div>' +
      '<div class="form-group"><label><?= e(__('admin.shipping.tier_price')) ?></label><input type="number" step="0.01" min="0" name="tier_price[]"></div>' +
      '<div class="form-group"><button type="button" class="btn btn-sm btn-secondary" onclick="removeTierRow(this)"><?= e(__('common.delete')) ?></button></div>';
    container.appendChild(row);
  }
  function removeTierRow(btn) {
    var rows = document.querySelectorAll('.tier-row');
    if (rows.length <= 1) {
      btn.closest('.tier-row').querySelectorAll('input').forEach(function (i) { i.value = ''; });
      return;
    }
    btn.closest('.tier-row').remove();
  }
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
