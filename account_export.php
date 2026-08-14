<?php
require_once __DIR__ . '/includes/bootstrap.php';
requireCustomerLogin();
$customer = currentCustomer();

$data = GdprTools::exportData($customer['id']);

header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="my-data-' . date('Y-m-d') . '.json"');
echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
