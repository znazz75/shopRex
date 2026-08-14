<?php
require_once __DIR__ . '/includes/bootstrap.php';
requireAdminPermission('customers');

$id = (int)($_GET['id'] ?? 0);
$data = GdprTools::exportData($id);

if (!$data) {
    setFlash('error', __('admin.customer_view.not_found'));
    redirect(rtrim(SITE_URL, '/') . '/admin/customers.php');
}

header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="customer-' . $id . '-data-' . date('Y-m-d') . '.json"');
echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
