<?php
/**
 * AJAX endpoint used by the jQuery UI Sortable image list in
 * admin/product_images.php.
 */
require_once __DIR__ . '/includes/bootstrap.php';
header('Content-Type: application/json');

if (!currentAdmin() || !adminCan(currentAdmin(), 'products')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

if (!verifyCsrf()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$productId = (int)($_POST['product_id'] ?? 0);
$ids = array_map('intval', $_POST['ids'] ?? []);

if (!$productId || empty($ids)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing product_id or ids']);
    exit;
}

$stmt = db()->prepare('UPDATE product_images SET sort_order = ? WHERE id = ? AND product_id = ?');
foreach ($ids as $index => $id) {
    $stmt->execute([$index, $id, $productId]);
}

echo json_encode(['success' => true]);
