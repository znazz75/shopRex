<?php
/**
 * AJAX endpoint used by the jQuery UI Sortable lists in admin/menus.php.
 * Persists a new sibling order (does not change parent_id, so items can
 * only ever be reordered among their existing siblings - never re-parented
 * by a drag).
 */
require_once __DIR__ . '/includes/bootstrap.php';
header('Content-Type: application/json');

if (!currentAdmin() || !adminCan(currentAdmin(), 'menus')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

if (!verifyCsrf()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$location = in_array($_POST['location'] ?? '', ['main', 'footer'], true) ? $_POST['location'] : null;
$ids = array_map('intval', $_POST['ids'] ?? []);

if (!$location || empty($ids)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing location or ids']);
    exit;
}

$stmt = db()->prepare('UPDATE menu_items SET sort_order = ? WHERE id = ? AND location = ?');
foreach ($ids as $index => $id) {
    $stmt->execute([$index, $id, $location]);
}

echo json_encode(['success' => true]);
