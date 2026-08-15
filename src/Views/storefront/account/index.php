<?php
/**
 * Storefront "My Account" dashboard - signed-in customer's own order
 * history plus GDPR self-service links (export/delete). Rendered by
 * Controllers\Storefront\AccountController at /account. This file is just
 * the body; Core\Renderer::render() wraps it with the theme's
 * header.php/footer.php.
 *
 * @var array $customer         The logged-in customer's row (first_name/
 *                                last_name/email at minimum - see
 *                                Core\Auth\CustomerAuth::current()).
 * @var array $orders           This customer's past orders, newest first,
 *                                each with order_number/created_at/status/
 *                                total/id.
 * @var array $invoicesByOrder  A lookup set, NOT a map of real data - keys
 *                                are order IDs that have a generated PDF
 *                                invoice, values are meaningless (it's built
 *                                via array_flip() in the controller purely
 *                                so `isset($invoicesByOrder[$id])` is a fast
 *                                "does this order have an invoice?" check).
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
            <?php // Invoice download icon only shows up once an invoice PDF
                  // has actually been generated for this order (e.g. after
                  // payment) - see $invoicesByOrder above. ?>
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
<?php // GDPR self-service: /account/export streams the customer's own data
      // as a download (Services\GdprService), /account/delete leads to the
      // password-confirmation step in delete_confirm.php below before
      // anything is actually deleted. ?>
<div class="d-flex gap-2 flex-wrap">
  <a class="btn btn-outline-secondary btn-sm" href="<?= rtrim(SITE_URL, '/') ?>/account/export"><i class="bi bi-download me-1"></i><?= e(__('account.export_data')) ?></a>
  <a class="btn btn-outline-danger btn-sm" href="<?= rtrim(SITE_URL, '/') ?>/account/delete"><i class="bi bi-trash me-1"></i><?= e(__('account.delete_account')) ?></a>
</div>
