<?php
/**
 * Streams an invoice PDF to whoever is allowed to see it: the customer who
 * placed the order, or any signed-in admin. Everyone else gets a 403 - the
 * file itself lives outside the web root's reach (uploads/invoices/.htaccess
 * denies direct access), this is the only way to it.
 */
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
    die('Order not found.');
}

$customer = currentCustomer();
$isOwner = $customer && $order['customer_id'] !== null && (int)$order['customer_id'] === (int)$customer['id'];

$isAdmin = false;
if (!empty($_SESSION['admin_id'])) {
    $adminStmt = db()->prepare("SELECT id FROM admin_users WHERE id = ? AND status = 'active'");
    $adminStmt->execute([$_SESSION['admin_id']]);
    $isAdmin = (bool)$adminStmt->fetch();
}

if (!$isOwner && !$isAdmin) {
    http_response_code(403);
    die('You do not have permission to view this invoice.');
}

$invStmt = db()->prepare('SELECT * FROM invoices WHERE order_id = ? LIMIT 1');
$invStmt->execute([$order['id']]);
$invoice = $invStmt->fetch();

if (!$invoice || !is_file($invoice['pdf_path'])) {
    http_response_code(404);
    die('Invoice not available for this order yet.');
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . basename($invoice['invoice_number']) . '.pdf"');
header('Content-Length: ' . filesize($invoice['pdf_path']));
header('X-Content-Type-Options: nosniff');
readfile($invoice['pdf_path']);
exit;
