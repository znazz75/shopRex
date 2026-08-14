<?php
/**
 * Bootstraps every back-office request.
 */
require_once __DIR__ . '/../../config/config.php';

if (!IS_INSTALLED) {
    header('Location: ../install.php');
    exit;
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/i18n.php';
require_once __DIR__ . '/../../includes/SimplePdf.php';
require_once __DIR__ . '/../../includes/InvoiceGenerator.php';
require_once __DIR__ . '/../../includes/Mailer.php';
require_once __DIR__ . '/../../includes/ImageProcessor.php';
require_once __DIR__ . '/../../includes/GdprTools.php';
require_once __DIR__ . '/../../includes/GdprCleanup.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/auth.php';
