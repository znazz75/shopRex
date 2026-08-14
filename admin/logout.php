<?php
require_once __DIR__ . '/includes/bootstrap.php';
unset($_SESSION['admin_id']);
redirect(rtrim(SITE_URL, '/') . '/admin/login.php');
