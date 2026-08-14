<?php
require_once __DIR__ . '/includes/bootstrap.php';
unset($_SESSION['customer_id']);
setFlash('success', 'You have been signed out.');
redirect(rtrim(SITE_URL, '/') . '/index.php');
