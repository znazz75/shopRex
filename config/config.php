<?php
/**
 * shopRex - global configuration.
 *
 * On a fresh checkout, config/installed.php does not exist yet - visiting
 * any page redirects to install.php, which collects the database and
 * initial admin account details and writes that file for you. Everything
 * below is either installer-provided (DB_*) or falls back to environment
 * variables / sensible defaults for advanced/unattended setups.
 */

define('SHOPREX_INSTALLED_FILE', __DIR__ . '/installed.php');
define('IS_INSTALLED', file_exists(SHOPREX_INSTALLED_FILE));

if (IS_INSTALLED) {
    // Defines DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS, and SITE_URL, and
    // may also override ADMIN_EMAIL / MAIL_FROM - written by install.php
    // (and re-written by Admin -> Settings if the Site URL is edited later).
    require SHOPREX_INSTALLED_FILE;
}

function isHttpsRequest(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

/**
 * Best-effort guess at "scheme://host/base-path" for wherever this project
 * currently lives - including a subdirectory, e.g. http://localhost/shopRex.
 * Compares this file's real path against the web server's document root, so
 * it works no matter how deep the currently-running script is nested (root
 * pages, admin/, admin/cron/, ...). Used to prefill the installer's Site URL
 * field and as a fallback default before installation; the authoritative
 * value normally comes from config/installed.php (SITE_URL), set once at
 * install time and editable later from Admin -> Settings, since a live
 * per-request guess isn't available at all in a CLI/cron context.
 */
function detectSiteUrl(): string
{
    $scheme = isHttpsRequest() ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    // Prefer $_SERVER['SCRIPT_NAME'] (Apache's own, already-resolved URL
    // path for the currently-running script) over comparing filesystem
    // paths: dirname(__DIR__) is resolved through PHP's own symlink
    // handling, which silently loses the subdirectory whenever the
    // project root itself is reached via a symlinked/junctioned htdocs
    // entry (a common local-dev setup, e.g. XAMPP) - DOCUMENT_ROOT never
    // gets that same resolution, so the two stopped being comparable.
    // detectSiteUrl() is only ever meaningfully called from install.php
    // itself (nothing else is reachable pre-install - see IS_INSTALLED
    // above), so "the directory install.php is in" is exactly the answer.
    if (isset($_SERVER['SCRIPT_NAME'])) {
        $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
        if ($basePath === '.') {
            $basePath = '';
        }
        return $scheme . '://' . $host . $basePath;
    }

    // CLI/cron fallback (no SCRIPT_NAME) - best-effort filesystem
    // comparison; only used as an inert placeholder default before
    // installation, never rendered anywhere.
    $documentRoot = isset($_SERVER['DOCUMENT_ROOT']) ? str_replace('\\', '/', rtrim($_SERVER['DOCUMENT_ROOT'], '/\\')) : '';
    $projectRoot = str_replace('\\', '/', rtrim(dirname(__DIR__), '/\\')); // parent of config/ = project root

    $basePath = '';
    if ($documentRoot !== '' && stripos($projectRoot, $documentRoot) === 0) {
        $basePath = rtrim(substr($projectRoot, strlen($documentRoot)), '/');
    }

    return $scheme . '://' . $host . $basePath;
}

// ---------------------------------------------------------------
// Database (installer-provided values win; env vars are a manual fallback)
// ---------------------------------------------------------------
if (!defined('DB_HOST')) define('DB_HOST', getenv('SHOPREX_DB_HOST') ?: '127.0.0.1');
if (!defined('DB_PORT')) define('DB_PORT', getenv('SHOPREX_DB_PORT') ?: '3306');
if (!defined('DB_NAME')) define('DB_NAME', getenv('SHOPREX_DB_NAME') ?: 'shoprex');
if (!defined('DB_USER')) define('DB_USER', getenv('SHOPREX_DB_USER') ?: 'root');
if (!defined('DB_PASS')) define('DB_PASS', getenv('SHOPREX_DB_PASS') ?: '');

// ---------------------------------------------------------------
// Site
// ---------------------------------------------------------------
// Mirrors the VERSION file at the project root - see CONTRIBUTING.md's
// "Versioning" section for the project's release/bump convention. Kept as
// a literal string (not computed from the file) so it's available even in
// contexts that would rather not touch the filesystem on every request.
define('SHOPREX_VERSION', '3.02');
define('SITE_NAME', 'shopRex');
if (!defined('SITE_URL')) define('SITE_URL', getenv('SHOPREX_SITE_URL') ?: detectSiteUrl());
if (!defined('ADMIN_EMAIL')) define('ADMIN_EMAIL', getenv('SHOPREX_ADMIN_EMAIL') ?: 'admin@example.com');

// Force HTTPS whenever the configured Site URL itself uses https:// - that's
// treated as "this site should always be served over HTTPS". A http://
// Site URL (e.g. local development, `php -S localhost:8000`) never
// triggers a redirect, so this can't break local dev or a not-yet-HTTPS
// staging site. isHttpsRequest() already understands X-Forwarded-Proto, so
// this is also safe behind a TLS-terminating reverse proxy/load balancer.
if (str_starts_with(SITE_URL, 'https://') && !isHttpsRequest() && PHP_SAPI !== 'cli') {
    $httpsUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? parse_url(SITE_URL, PHP_URL_HOST)) . ($_SERVER['REQUEST_URI'] ?? '/');
    header('Location: ' . $httpsUrl, true, 301);
    exit;
}

define('CURRENCY_SYMBOL', '€');
define('UPLOAD_DIR', __DIR__ . '/../uploads/products/');
define('UPLOAD_URL', SITE_URL . '/uploads/products/');
// Never web-accessible (see uploads/invoices/.htaccess) - served only
// through invoice_download.php, which checks the requester is the
// order's owner or an admin.
define('INVOICE_DIR', __DIR__ . '/../uploads/invoices/');

// ---------------------------------------------------------------
// Email (used by Services\Mailer)
// PHP's built-in mail() is used by default. To send through a real SMTP
// account (recommended, e.g. Gmail/SendGrid/Mailgun), fill in SMTP_*
// and wire up PHPMailer per the README.
// ---------------------------------------------------------------
if (!defined('MAIL_FROM')) define('MAIL_FROM', getenv('SHOPREX_MAIL_FROM') ?: 'shop@example.com');
define('MAIL_FROM_NAME', SITE_NAME);
define('SMTP_HOST', getenv('SHOPREX_SMTP_HOST') ?: '');
define('SMTP_PORT', getenv('SHOPREX_SMTP_PORT') ?: 587);
define('SMTP_USER', getenv('SHOPREX_SMTP_USER') ?: '');
define('SMTP_PASS', getenv('SHOPREX_SMTP_PASS') ?: '');

// ---------------------------------------------------------------
// Payment gateways
// All keys below default to SANDBOX/TEST placeholders. Replace with
// real credentials before going live. See README.md for setup notes.
// ---------------------------------------------------------------

// PayPal (REST API, sandbox by default)
define('PAYPAL_MODE', getenv('SHOPREX_PAYPAL_MODE') ?: 'sandbox'); // 'sandbox' or 'live'
define('PAYPAL_CLIENT_ID', getenv('SHOPREX_PAYPAL_CLIENT_ID') ?: 'YOUR_PAYPAL_CLIENT_ID');
define('PAYPAL_CLIENT_SECRET', getenv('SHOPREX_PAYPAL_CLIENT_SECRET') ?: 'YOUR_PAYPAL_CLIENT_SECRET');
define('PAYPAL_API_BASE', PAYPAL_MODE === 'live'
    ? 'https://api-m.paypal.com'
    : 'https://api-m.sandbox.paypal.com');

// Credit card (Stripe Checkout, test mode by default)
define('STRIPE_PUBLISHABLE_KEY', getenv('SHOPREX_STRIPE_PUBLISHABLE_KEY') ?: 'pk_test_yourkey');
define('STRIPE_SECRET_KEY', getenv('SHOPREX_STRIPE_SECRET_KEY') ?: 'sk_test_yourkey');

// Bank transfer - details shown to the customer at checkout
define('BANK_ACCOUNT_HOLDER', 'shopRex GmbH');
define('BANK_IBAN', 'DE00 0000 0000 0000 0000 00');
define('BANK_BIC', 'XXXXXXXX');
define('BANK_NAME', 'Musterbank');

// ---------------------------------------------------------------
// Misc
// ---------------------------------------------------------------
// Tax rates now live in the tax_rates table (Admin -> Tax Rates), not here.
define('FLAT_SHIPPING_COST', 4.90);
define('FREE_SHIPPING_THRESHOLD', 50.00);

error_reporting(E_ALL);
ini_set('display_errors', getenv('SHOPREX_DEBUG') ? '1' : '0');
date_default_timezone_set('Europe/Berlin');

// ---------------------------------------------------------------
// Session hardening (defense-in-depth alongside the csrf_token check in
// Core\Csrf - install.php keeps its own small self-contained
// requireCsrf()/verifyCsrf() copies, since it can't use that class this early):
//   HttpOnly - client-side JS (incl. injected via XSS) can't read the cookie
//   SameSite=Lax - blocks the cookie on cross-site POST (the actual CSRF
//     vector) while still allowing it on top-level GET navigation, which
//     the PayPal/Stripe redirect-back flow (/checkout/capture) needs
//   Secure - only sent over HTTPS, auto-detected so local HTTP dev still works
// ---------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => isHttpsRequest(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
