<?php
require_once __DIR__ . '/includes/bootstrap.php';

$orderNumber = $_GET['order'] ?? '';
$stmt = db()->prepare(
    "SELECT o.*, COALESCE(c.email, o.guest_email) AS customer_email
     FROM orders o LEFT JOIN customers c ON c.id = o.customer_id
     WHERE o.order_number = ?"
);
$stmt->execute([$orderNumber]);
$order = $stmt->fetch();

if (!$order) {
    http_response_code(404);
    $pageTitle = __('order.not_found_title');
    require themeTemplatePath('header.php');
    echo '<p>' . e(__('order.not_found_text')) . '</p>';
    require themeTemplatePath('footer.php');
    exit;
}

// Access control: the order's own customer, an admin, or the session that
// just placed this exact order (covers guest checkout - see
// checkout_process.php, which sets last_order_id right after the order is
// created). Order numbers alone are not a secret worth trusting here - only
// ~24 bits of randomness with a predictable date prefix - so without this
// check anyone who guesses/brute-forces one could read another customer's
// name, address, email, and order contents (see docs/SECURITY_AUDIT.md
// finding #4).
$customer = currentCustomer();
$isOwner = $customer && $order['customer_id'] !== null && (int)$order['customer_id'] === (int)$customer['id'];

$isAdmin = false;
if (!empty($_SESSION['admin_id'])) {
    $adminStmt = db()->prepare("SELECT id FROM admin_users WHERE id = ? AND status = 'active'");
    $adminStmt->execute([$_SESSION['admin_id']]);
    $isAdmin = (bool)$adminStmt->fetch();
}

$isJustPlaced = !empty($_SESSION['last_order_id']) && (int)$_SESSION['last_order_id'] === (int)$order['id'];

if (!$isOwner && !$isAdmin && !$isJustPlaced) {
    http_response_code(403);
    $pageTitle = __('order.not_found_title');
    require themeTemplatePath('header.php');
    echo '<p>' . e(__('order.not_found_text')) . '</p>';
    require themeTemplatePath('footer.php');
    exit;
}

$itemStmt = db()->prepare('SELECT * FROM order_items WHERE order_id = ?');
$itemStmt->execute([$order['id']]);
$items = $itemStmt->fetchAll();

$invoiceStmt = db()->prepare('SELECT * FROM invoices WHERE order_id = ? LIMIT 1');
$invoiceExists = false;
try {
    $invoiceStmt->execute([$order['id']]);
    $invoiceExists = (bool)$invoiceStmt->fetch();
} catch (Throwable $e) {
    // invoices table not present yet (e.g. pre-migration DB) - just hide the link.
}

$pageTitle = __('order.confirmation_title');
require themeTemplatePath('header.php');
?>

<?php if (!empty($order['is_test_order'])): ?>
  <div class="alert alert-warning"><i class="bi bi-flask me-1"></i><strong><?= e(__('nav.test_mode_title')) ?></strong> &mdash; <?= e(__('order.test_banner')) ?></div>
<?php endif; ?>

<div class="alert alert-success"><i class="bi bi-check-circle me-1"></i> <?= e(__('order.thank_you')) ?></div>

<p><?= e(__('order.number')) ?>: <strong><?= e($order['order_number']) ?></strong></p>
<p><?= e(__('order.status')) ?>: <span class="badge text-bg-secondary"><?= e(ucwords(str_replace('_', ' ', $order['status']))) ?></span>
   &middot; <?= e(__('order.payment')) ?>: <span class="badge text-bg-secondary"><?= e(ucwords(str_replace('_', ' ', $order['payment_status']))) ?></span></p>

<?php if ($order['payment_method'] === 'bank_transfer' && $order['payment_status'] === 'pending'): ?>
  <div class="alert alert-info">
    <strong><?= e(__('order.bank_transfer_instructions', ['amount' => formatPrice((float)$order['total'])])) ?></strong><br>
    <?= e(__('order.bank_account_holder')) ?>: <?= e(getSetting('bank_account_holder', BANK_ACCOUNT_HOLDER)) ?><br>
    <?= e(__('order.bank_iban')) ?>: <?= e(getSetting('bank_iban', BANK_IBAN)) ?><br>
    <?= e(__('order.bank_bic')) ?>: <?= e(getSetting('bank_bic', BANK_BIC)) ?><br>
    <?= e(__('order.bank_name')) ?>: <?= e(getSetting('bank_name', BANK_NAME)) ?><br>
    <?= e(__('order.bank_reference')) ?>: <?= e($order['order_number']) ?><br><br>
    <?= e(__('order.will_ship_on_payment')) ?>
  </div>
<?php endif; ?>

<?php if ($order['payment_method'] === 'invoice' && $order['payment_status'] === 'pending'): ?>
  <div class="alert alert-info">
    <?= e(__('order.invoice_instructions')) ?>
  </div>
<?php endif; ?>

<div class="table-responsive mt-3">
  <table class="table">
    <thead><tr><th><?= e(__('order.item')) ?></th><th><?= e(__('common.quantity')) ?></th><th class="text-end"><?= e(__('common.total')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($items as $item): ?>
      <tr>
        <td><?= e($item['product_name']) ?><?= $item['option_summary'] ? '<br><small class="text-secondary">' . e($item['option_summary']) . '</small>' : '' ?></td>
        <td><?= (int)$item['quantity'] ?></td>
        <td class="text-end"><?= formatPrice((float)$item['total_price']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="card" style="max-width:360px;margin-left:auto;">
  <div class="card-body">
    <div class="d-flex justify-content-between mb-2"><span><?= e(__('common.subtotal')) ?></span><span><?= formatPrice((float)$order['subtotal']) ?></span></div>
    <div class="d-flex justify-content-between mb-2"><span><?= e(__('common.shipping')) ?><?= !empty($order['shipping_method_name']) ? ' (' . e($order['shipping_method_name']) . ')' : '' ?></span><span><?= formatPrice((float)$order['shipping_cost']) ?></span></div>
    <div class="d-flex justify-content-between mb-2"><span><?= e(__('common.tax')) ?></span><span><?= formatPrice((float)$order['tax_total']) ?></span></div>
    <hr>
    <div class="d-flex justify-content-between fw-bold fs-5"><span><?= e(__('common.total')) ?></span><span><?= formatPrice((float)$order['total']) ?></span></div>
  </div>
</div>

<p class="mt-4"><?= e(__('order.confirmation_email_sent', ['email' => $order['customer_email']])) ?></p>
<?php if ($invoiceExists): ?>
  <p><a class="btn btn-outline-secondary btn-sm" href="<?= rtrim(SITE_URL, '/') ?>/invoice_download.php?order=<?= urlencode($order['order_number']) ?>"><i class="bi bi-file-earmark-pdf me-1"></i><?= e(__('order.invoice_download')) ?></a></p>
<?php endif; ?>
<p><a href="<?= rtrim(SITE_URL, '/') ?>/index.php"><?= e(__('common.continue_shopping')) ?></a></p>

<?php require themeTemplatePath('footer.php'); ?>
