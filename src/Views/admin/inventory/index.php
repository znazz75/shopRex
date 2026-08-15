<?php
/**
 * Admin -> Inventory: stock levels and manual adjustments, split into two
 * separate tables because stock is tracked differently depending on
 * whether a product has options - see CLAUDE.md's Cart section: "Stock
 * for a product with options is tracked per exact combination
 * (product_variants/product_variant_values), not per single option
 * value." Both tables' adjustment forms post to the same
 * /admin/inventory/adjust endpoint and both write to the shared
 * inventory_log audit trail shown in the third table.
 *
 * @var array $products  Products with NO options (so a single flat stock_quantity/stock_threshold applies directly to the product) - "Stock Levels" table.
 * @var array $variants   Every option-combination row (product_variants) for products that DO have options, each with its own stock_quantity/stock_threshold - "Variant Stock" table.
 * @var array $recentLog  Recent rows from inventory_log (the audit trail every adjustment writes to, including automatic ones from real order fulfillment) - "Recent Movements" table.
 */
// Both adjustment forms below post here; kept as one variable so the URL
// only needs building once instead of repeating rtrim(SITE_URL, '/') twice.
$adjustUrl = rtrim(SITE_URL, '/') . '/admin/inventory/adjust';
?>
<div class="page-header"><h1><?= e(__('admin.inventory')) ?></h1></div>

<div class="card">
  <h2 style="margin-top:0;"><?= e(__('admin.inventory.stock_levels')) ?></h2>
  <table class="data-table">
    <thead><tr><th><?= e(__('admin.products')) ?></th><th><?= e(__('admin.dashboard.sku')) ?></th><th><?= e(__('admin.dashboard.stock')) ?></th><th><?= e(__('admin.dashboard.threshold')) ?></th><th><?= e(__('admin.inventory.adjust')) ?></th></tr></thead>
    <tbody>
    <?php /* Stock badge turns red/"low" once quantity drops to or below this product's own configured threshold - same rule the dashboard's low-stock widget uses. */ ?>
    <?php foreach ($products as $p): ?>
      <tr>
        <td><?= e($p['name']) ?></td>
        <td><?= e($p['sku']) ?></td>
        <td><?= $p['stock_quantity'] <= $p['stock_threshold'] ? '<span class="badge badge-low">' . (int)$p['stock_quantity'] . '</span>' : (int)$p['stock_quantity'] ?></td>
        <td><?= (int)$p['stock_threshold'] ?></td>
        <td>
          <?php /* change_qty is a signed delta typed by the admin (e.g. "+10" or "-3"), not an absolute new stock value - the controller adds it to the current quantity. "reason" is just a label recorded in inventory_log for the audit trail below, it doesn't change how the adjustment is applied. */ ?>
          <form method="post" action="<?= e($adjustUrl) ?>" style="display:flex;gap:6px;">
            <?= csrfField() ?>
            <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
            <input type="number" name="change_qty" placeholder="<?= e(__('admin.inventory.qty_placeholder')) ?>" style="width:90px;padding:6px;border:1px solid var(--color-border);border-radius:6px;" required>
            <select name="reason" style="padding:6px;border:1px solid var(--color-border);border-radius:6px;">
              <option value="restock"><?= e(__('admin.inventory.reason_restock')) ?></option>
              <option value="return"><?= e(__('admin.inventory.reason_return')) ?></option>
              <option value="adjustment"><?= e(__('admin.inventory.reason_adjustment')) ?></option>
            </select>
            <button class="btn btn-sm" type="submit"><?= e(__('admin.inventory.apply')) ?></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (empty($products)): ?><tr><td colspan="5"><?= e(__('admin.inventory.no_option_less_products')) ?></td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<div class="card">
  <h2 style="margin-top:0;"><?= e(__('admin.inventory.variant_stock')) ?></h2>
  <p style="color:var(--color-muted);font-size:13px;margin-top:0;"><?= e(__('admin.inventory.variant_stock_hint')) ?></p>
  <table class="data-table">
    <thead><tr><th><?= e(__('admin.products')) ?></th><th><?= e(__('admin.product_edit.options_legend')) ?></th><th><?= e(__('admin.dashboard.sku')) ?></th><th><?= e(__('admin.dashboard.stock')) ?></th><th><?= e(__('admin.inventory.adjust')) ?></th></tr></thead>
    <tbody>
    <?php /* combo_label is the human-readable option combination this row represents, e.g. "Size: L / Color: Red" - each row is one exact combination, tracked and adjusted independently of every other combination of the same product. */ ?>
    <?php foreach ($variants as $v): ?>
      <tr>
        <td><?= e($v['product_name']) ?></td>
        <td><?= e($v['combo_label'] ?? '') ?></td>
        <td><?= e($v['sku']) ?></td>
        <td><?= $v['stock_quantity'] <= $v['stock_threshold'] ? '<span class="badge badge-low">' . (int)$v['stock_quantity'] . '</span>' : (int)$v['stock_quantity'] ?></td>
        <td>
          <?php /* Same delta-based adjustment form as the simple-products table above, but scoped to one specific variant_id (not just the product) so the stock change lands on the right combination. */ ?>
          <form method="post" action="<?= e($adjustUrl) ?>" style="display:flex;gap:6px;">
            <?= csrfField() ?>
            <input type="hidden" name="product_id" value="<?= (int)$v['product_id'] ?>">
            <input type="hidden" name="variant_id" value="<?= (int)$v['id'] ?>">
            <input type="number" name="change_qty" placeholder="<?= e(__('admin.inventory.qty_placeholder')) ?>" style="width:90px;padding:6px;border:1px solid var(--color-border);border-radius:6px;" required>
            <select name="reason" style="padding:6px;border:1px solid var(--color-border);border-radius:6px;">
              <option value="restock"><?= e(__('admin.inventory.reason_restock')) ?></option>
              <option value="return"><?= e(__('admin.inventory.reason_return')) ?></option>
              <option value="adjustment"><?= e(__('admin.inventory.reason_adjustment')) ?></option>
            </select>
            <button class="btn btn-sm" type="submit"><?= e(__('admin.inventory.apply')) ?></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (empty($variants)): ?><tr><td colspan="5"><?= e(__('admin.inventory.no_variants')) ?></td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<div class="card">
  <h2 style="margin-top:0;"><?= e(__('admin.inventory.recent_movements')) ?></h2>
  <table class="data-table">
    <thead><tr><th><?= e(__('common.date')) ?></th><th><?= e(__('admin.products')) ?></th><th><?= e(__('admin.inventory.change')) ?></th><th><?= e(__('admin.inventory.reason')) ?></th><th><?= e(__('admin.inventory.reference')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($recentLog as $log): ?>
      <tr>
        <td><?= e(formatLocalDate($log['created_at'], true)) ?></td>
        <td><?= e($log['product_name']) ?><?= !empty($log['variant_label']) ? '<br><small style="color:var(--color-muted);">' . e($log['variant_label']) . '</small>' : '' ?></td>
        <?php /* Sign-colored delta: red for a decrease, green for an increase; the leading "+" is added manually since PHP doesn't put one on positive numbers by default (negative numbers already come with their own "-"). */ ?>
        <td style="color: <?= $log['change_qty'] < 0 ? 'var(--color-error)' : 'var(--color-success)' ?>;"><?= $log['change_qty'] > 0 ? '+' : '' ?><?= (int)$log['change_qty'] ?></td>
        <td>
          <?= e($log['reason']) ?>
          <?php /* Test orders are logged here too (for a complete audit trail) but never actually decrement real stock - see CLAUDE.md's "Test accounts" section - this badge flags which log rows came from a test order rather than a real stock change. */ ?>
          <?php if ($log['is_test']): ?> <span class="badge badge-pending" title="<?= e(__('admin.inventory.test_order_title')) ?>"><?= e(__('admin.dashboard.test_badge')) ?></span><?php endif; ?>
        </td>
        <td><?= e($log['reference']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (empty($recentLog)): ?><tr><td colspan="5"><?= e(__('admin.inventory.none_yet')) ?></td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
