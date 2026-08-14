<?php
/**
 * @var array $customer
 * @var array $orders
 * @var array $invoicesByOrder
 */
?>

<h1 class="h3 mb-3"><?= e(__('account.title')) ?></h1>
<p class="text-secondary"><?= e(__('account.signed_in_as', ['name' => $customer['first_name'] . ' ' . $customer['last_name'], 'email' => $customer['email']])) ?></p>

<h2 class="h5 mt-4"><?= e(__('account.order_history')) ?></h2>
<?php if (empty($orders)): ?>
  <p class="text-secondary"><?= e(__('account.no_orders')) ?></p>
<?php else: ?>
  <div class="table-responsive">
    <table class="table">
      <thead><tr><th><?= e(__('order.number')) ?></th><th><?= e(__('common.date')) ?></th><th><?= e(__('common.status')) ?></th><th class="text-end"><?= e(__('common.total')) ?></th><th></th></tr></thead>
      <tbody>
      <?php foreach ($orders as $order): ?>
        <tr>
          <td><?= e($order['order_number']) ?></td>
          <td><?= e(formatLocalDate($order['created_at'])) ?></td>
          <td><span class="badge text-bg-secondary"><?= e(ucwords(str_replace('_', ' ', $order['status']))) ?></span></td>
          <td class="text-end"><?= formatPrice((float)$order['total']) ?></td>
          <td>
            <?php if (isset($invoicesByOrder[$order['id']])): ?>
              <a class="btn btn-sm btn-outline-secondary" href="<?= rtrim(SITE_URL, '/') ?>/order/<?= urlencode($order['order_number']) ?>/invoice"><i class="bi bi-file-earmark-pdf"></i></a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<hr class="my-4">
<h2 class="h5"><?= e(__('account.privacy_heading')) ?></h2>
<div class="d-flex gap-2 flex-wrap">
  <a class="btn btn-outline-secondary btn-sm" href="<?= rtrim(SITE_URL, '/') ?>/account/export"><i class="bi bi-download me-1"></i><?= e(__('account.export_data')) ?></a>
  <a class="btn btn-outline-danger btn-sm" href="<?= rtrim(SITE_URL, '/') ?>/account/delete"><i class="bi bi-trash me-1"></i><?= e(__('account.delete_account')) ?></a>
</div>
