<?php
/**
 * Bootstraps every storefront request: config, DB, helpers, session cart.
 */
require_once __DIR__ . '/../config/config.php';

if (!IS_INSTALLED) {
    header('Location: install.php');
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/Cart.php';
require_once __DIR__ . '/SimplePdf.php';
require_once __DIR__ . '/InvoiceGenerator.php';
require_once __DIR__ . '/Mailer.php';
require_once __DIR__ . '/PaymentGateway.php';
require_once __DIR__ . '/GdprTools.php';
require_once __DIR__ . '/GdprCleanup.php';
