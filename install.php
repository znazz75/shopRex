<?php
/**
 * shopRex installer.
 *
 * First-run setup wizard: checks server requirements, collects database
 * connection details (creating the database + importing the schema),
 * then creates the first Super Admin account. Once an admin account
 * exists, this script permanently refuses to run again - re-installing
 * over a live shop would wipe/duplicate data.
 *
 * Important constraint for anyone editing this file: it deliberately runs
 * OUTSIDE the normal src/ application stack (Core\Router/App/Container,
 * the ShopRex\ autoloader, etc.) because its whole job - creating
 * config/installed.php and the database those depend on - has to work
 * *before* any of that machinery has anything to connect to. That's why
 * this file is fully self-contained: e()/redirect()/the CSRF helpers/
 * writeInstalledConfigFile() below are small, deliberately duplicated
 * copies of the same-named functions elsewhere in the app (e()/CSRF handling
 * live on Core\Csrf and the view-helpers.php shim for everything else;
 * writeInstalledConfigFile() has an equivalent private method on
 * Controllers\Admin\SettingsAdminController for the "change site URL"
 * admin feature) rather than shared code, since nothing in src/ can be
 * safely required this early. This is also why this single file mixes PHP
 * request-handling logic with its own inline HTML template at the bottom
 * rather than using Core\Renderer/a Views file (again: those depend on the
 * app already being installed).
 */
require_once __DIR__ . '/config/config.php';

/** HTML-escapes $value for safe output in this file's inline template (prevents XSS from any value that ultimately came from user input) - null-safe, so `e($maybeNullValue)` never warns. */
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/** Used by this file only - every other page redirects via Core\Response::redirect(). */
function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

// ---------------------------------------------------------------
// CSRF protection - this file only (every other page's CSRF handling goes
// through Core\Csrf, a separate, unrelated implementation bound to the
// same $_SESSION['csrf_token'] key so a token from one still verifies
// against the other during the brief window this installer can still run).
// ---------------------------------------------------------------

/** Returns the current session's CSRF token, generating and storing a fresh cryptographically-random one the first time it's needed. */
function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Renders the hidden form field this file's <form>s embed so their POST submission carries the CSRF token back for verifyCsrf()/requireCsrf() to check. */
function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrfToken()) . '">';
}

/** True if the current POST request's csrf_token field matches this session's expected token - this file's guard against cross-site request forgery on its setup forms. */
function verifyCsrf(): bool
{
    $token = $_POST['csrf_token'] ?? '';
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    // Both sides must be a non-empty string before comparing - hash_equals('', '')
    // returns true, which would let a forged request with no token field pass
    // whenever the victim's session happens not to have generated one yet.
    // hash_equals() itself (rather than ===) is used for a timing-safe
    // comparison, so an attacker can't guess the token one byte at a time
    // by measuring how long the comparison takes to fail.
    return is_string($token) && $token !== '' && $sessionToken !== '' && hash_equals($sessionToken, $token);
}

/** Enforces verifyCsrf(), immediately halting the request with a 403 response if the check fails - call this at the top of any POST handler below. */
function requireCsrf(): void
{
    if (!verifyCsrf()) {
        http_response_code(403);
        die('Invalid or expired form submission (CSRF check failed). Please go back and try again.');
    }
}

/**
 * (Re)writes config/installed.php - the DB credentials + Site URL that
 * make up an installed site. Admin -> Settings' equivalent is
 * Controllers\Admin\SettingsAdminController::writeInstalledConfigFile(), a
 * byte-for-byte copy of this same function - kept separate rather than
 * shared, since this file runs before the src/ autoloader's dependencies
 * (config/database.php, a DB connection) are guaranteed to exist.
 */
function writeInstalledConfigFile(string $host, string $port, string $name, string $user, string $pass, string $siteUrl): bool
{
    // var_export() turns each value into valid PHP source representing
    // that exact string (with correct quoting/escaping), so the generated
    // file is safe to write even if e.g. the DB password contains a quote
    // character.
    $content = "<?php\n"
        . "// Generated by install.php / Admin -> Settings on " . date('c') . ".\n"
        . "// Contains your database password - keep this file private (already in .gitignore).\n"
        . "define('DB_HOST', " . var_export($host, true) . ");\n"
        . "define('DB_PORT', " . var_export($port, true) . ");\n"
        . "define('DB_NAME', " . var_export($name, true) . ");\n"
        . "define('DB_USER', " . var_export($user, true) . ");\n"
        . "define('DB_PASS', " . var_export($pass, true) . ");\n"
        . "define('SITE_URL', " . var_export(rtrim($siteUrl, '/'), true) . ");\n";
    return file_put_contents(SHOPREX_INSTALLED_FILE, $content) !== false;
}

/**
 * True once config/installed.php exists AND at least one admin account
 * has been created - i.e. setup fully completed.
 */
function installationIsComplete(): bool
{
    // IS_INSTALLED (set in config/config.php) is true only once
    // config/installed.php exists - without that file there's no DB to
    // even check, so bail out immediately rather than trying to connect.
    if (!IS_INSTALLED) {
        return false;
    }
    try {
        // Only required here (not at the top of the file) because it's
        // only safe to include once config/installed.php - which it
        // depends on - is confirmed to exist via the IS_INSTALLED check above.
        require_once __DIR__ . '/config/database.php';
        $count = Database::getConnection()->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
        return (int)$count > 0;
    } catch (Throwable $e) {
        return false; // DB unreachable or table missing - treat as not installed
    }
}

// $locked gates the whole rest of the page: once true, only the 'locked'
// (and 'done', see below) views are reachable - re-running the wizard
// against an already-set-up shop is refused everywhere.
$locked = installationIsComplete();
// Which page of the wizard the visitor asked for via ?step=... - defaults
// to the very first screen.
$requestedStep = $_GET['step'] ?? 'welcome';
// 'done' stays reachable even once $locked is true - otherwise the
// redirect from the admin-creation step (to install.php?step=done) landed
// on this same request with installation now complete, so the ternary
// below would immediately override it back to 'locked' and the one-time
// "Setup complete!" confirmation was never actually seen. 'done' has no
// POST handler and reveals nothing sensitive, so it's fine to stay
// reachable indefinitely - equivalent to the 'locked' view either way.
$step = ($locked && $requestedStep !== 'done') ? 'locked' : $requestedStep;
$errors = [];
$old = [];

// ---------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------

/**
 * Split a .sql file into individual statements, respecting single-quoted
 * string literals (incl. '' escaped quotes) so a semicolon inside a
 * string - e.g. inline CSS in a seeded HTML email template,
 * style="color:#fff;padding:4px;" - never gets mistaken for a statement
 * terminator. A naive explode(';', ...) would silently corrupt exactly
 * that kind of content.
 */
function splitSqlStatements(string $sql): array
{
    $statements = [];
    $current = '';
    // Tracks whether the character-by-character scan below is currently
    // "inside" a single-quoted SQL string literal - a semicolon only ends a
    // statement when this is false.
    $inString = false;
    $length = strlen($sql);

    // Walk the SQL text one byte at a time (a tiny hand-rolled state
    // machine) rather than a regex/explode, since correctly handling
    // quoted strings (including escaped '' quotes inside them) needs to
    // track "am I inside a string right now?" as it goes.
    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $current .= $char;

        if ($char === "'") {
            if ($inString && ($i + 1) < $length && $sql[$i + 1] === "'") {
                // Escaped '' quote inside a string literal - consume both
                // characters and stay inside the string.
                $current .= $sql[$i + 1];
                $i++;
            } else {
                // A real (non-escaped) quote toggles in/out of string mode.
                $inString = !$inString;
            }
        } elseif ($char === ';' && !$inString) {
            // A semicolon outside any string literal really does end a
            // statement - trim stray whitespace/the semicolon itself and
            // start accumulating the next statement from scratch.
            $statements[] = trim($current, "; \t\n\r\0\x0B");
            $current = '';
        }
    }

    // Whatever's left after the loop (a final statement with no trailing
    // semicolon in the file) still counts.
    $tail = trim($current);
    if ($tail !== '') {
        $statements[] = $tail;
    }

    // Drop any accidentally-empty entries (e.g. from consecutive ";;" or
    // blank stretches of the file).
    return array_filter($statements, fn($s) => $s !== '');
}

/**
 * Executes every statement in a .sql file ($path) against $pdo - used for
 * both schema.sql (always) and seed_demo.sql (only if the visitor opted
 * into demo content). Returns an array of error messages for any statement
 * that failed for a reason other than "already exists" (see below), so the
 * caller can decide whether the overall import actually succeeded.
 */
function runSqlFile(PDO $pdo, string $path): array
{
    $sql = file_get_contents($path);
    // Strip whole-line SQL comments (lines starting with --) before
    // splitting into statements - a comment line is never itself part of a
    // statement, so this keeps splitSqlStatements()'s job simpler.
    $lines = array_filter(explode("\n", $sql), fn($l) => !str_starts_with(ltrim($l), '--'));
    $statements = splitSqlStatements(implode("\n", $lines));

    $errors = [];
    foreach ($statements as $statement) {
        if ($statement === '') {
            continue;
        }
        try {
            $pdo->exec($statement);
        } catch (PDOException $e) {
            // Re-running against an already-provisioned database is common
            // after an interrupted install - ignore "already exists", surface
            // anything else.
            if (!str_contains($e->getMessage(), 'already exists') && !str_contains($e->getMessage(), 'Duplicate')) {
                $errors[] = $e->getMessage();
            }
        }
    }
    return $errors;
}

// ---------------------------------------------------------------
// Step: database (POST handling)
// ---------------------------------------------------------------
if ($step === 'database' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Halts the request with a 403 if the submitted csrf_token doesn't
    // match this session's - see requireCsrf() above.
    requireCsrf();

    $host = trim($_POST['db_host'] ?? '');
    $port = trim($_POST['db_port'] ?? '3306');
    $name = trim($_POST['db_name'] ?? '');
    $user = trim($_POST['db_user'] ?? '');
    $pass = $_POST['db_pass'] ?? '';
    $siteUrl = rtrim(trim($_POST['site_url'] ?? ''), '/');
    $installDemo = !empty($_POST['install_demo']);
    // Remembered so the form below can re-fill these fields with what the
    // visitor typed if validation fails, instead of clearing the form.
    // Deliberately excludes $pass - never echo a submitted password back
    // into the page, even the visitor's own.
    $old = compact('host', 'port', 'name', 'user', 'siteUrl');

    if ($host === '' || $name === '' || $user === '') {
        $errors[] = 'Host, database name, and database user are required.';
    }
    // Identifiers (a database name here) can't be bound as PDO parameters,
    // so $name is interpolated directly into raw SQL below - this whitelist
    // is the only thing standing between it and SQL injection. If you ever
    // touch this validation, keep it at least this strict (see
    // docs/SECURITY_AUDIT.md finding #9).
    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        $errors[] = 'Database name may only contain letters, numbers, and underscores.';
    }
    if ($siteUrl === '' || !preg_match('~^https?://~i', $siteUrl)) {
        $errors[] = 'Site URL is required and must start with http:// or https://.';
    }

    if (!$errors) {
        try {
            // ERRMODE_EXCEPTION means a failed query throws PDOException
            // (caught below) rather than needing a manual error check after
            // every call; ATTR_TIMEOUT keeps a misconfigured/unreachable
            // host from hanging the request indefinitely.
            $pdo = new PDO(
                "mysql:host=$host;port=$port;charset=utf8mb4",
                $user,
                $pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
            );
            // Create the database if it doesn't exist yet (the common
            // case), then switch to it - $name is safe to interpolate here
            // only because of the strict whitelist regex validated above.
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `$name`");

            // Always import the full schema; only import demo sample data
            // (categories/products) if the visitor opted in via the
            // checkbox.
            $sqlErrors = runSqlFile($pdo, __DIR__ . '/sql/schema.sql');
            if ($installDemo) {
                $sqlErrors = array_merge($sqlErrors, runSqlFile($pdo, __DIR__ . '/sql/seed_demo.sql'));
            }

            // Structure is only considered good if the core table exists,
            // regardless of any individually-skipped statements above.
            $hasCoreTable = $pdo->query("SHOW TABLES LIKE 'admin_users'")->fetchColumn();
            if (!$hasCoreTable) {
                $errors[] = 'Schema import failed: ' . implode(' / ', $sqlErrors ?: ['unknown error']);
            } elseif (!writeInstalledConfigFile($host, $port, $name, $user, $pass, $siteUrl)) {
                $errors[] = 'Database is ready, but config/installed.php could not be written. Check that the config/ folder is writable and try again.';
            } else {
                // Everything succeeded - move on to creating the admin
                // account. A redirect (not just changing $step) so a page
                // refresh on the next step doesn't resubmit this database
                // setup form.
                redirect('install.php?step=admin');
            }
        } catch (PDOException $e) {
            $errors[] = 'Could not connect: ' . $e->getMessage();
        }
    }
}

// ---------------------------------------------------------------
// Step: admin account (POST handling)
// ---------------------------------------------------------------
if ($step === 'admin' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    // Can't create an admin account without a database to put it in - if
    // somehow reached before the database step completed, bounce back to
    // the start of the wizard rather than fataling.
    if (!IS_INSTALLED) {
        redirect('install.php');
    }
    require_once __DIR__ . '/config/database.php';
    $pdo = Database::getConnection();

    // Defense-in-depth against double-submission (e.g. two browser tabs,
    // or a page reload after this already succeeded once) - refuse to
    // create a second Super Admin account.
    if ((int)$pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn() > 0) {
        redirect('install.php'); // already completed elsewhere - don't create a second one
    }

    $username = trim($_POST['username'] ?? '');
    // FILTER_VALIDATE_EMAIL returns the email string if valid, or false -
    // used directly as the "is it valid" check below via `!$email`.
    $email = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);
    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';
    // Remembered for re-filling the form on validation failure - the raw
    // (possibly-invalid) submitted email is kept here rather than the
    // filtered $email, so the visitor sees exactly what they typed.
    // Passwords are deliberately excluded, same reasoning as the database
    // step above.
    $old = ['username' => $username, 'email' => $_POST['email'] ?? ''];

    if ($username === '') $errors[] = 'Username is required.';
    if (!$email) $errors[] = 'A valid email address is required.';
    if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
    if ($password !== $passwordConfirm) $errors[] = 'Passwords do not match.';

    if (!$errors) {
        // password_hash() with PASSWORD_DEFAULT (bcrypt) - the password is
        // never stored in plain text, only its hash.
        $stmt = $pdo->prepare(
            'INSERT INTO admin_users (username, email, password_hash, role, status) VALUES (?, ?, ?, "super_admin", "active")'
        );
        $stmt->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT)]);
        // Also seed the shop's public contact email setting from the same
        // address, so Admin -> Settings isn't left blank on a fresh install.
        $pdo->prepare('UPDATE settings SET setting_value = ? WHERE setting_key = "shop_email"')->execute([$email]);
        redirect('install.php?step=done');
    }
}

// Requirement checks shown on the welcome step. Each entry's 'ok' is
// evaluated right here (not lazily), and the uploads/ check doubles as an
// action: if the folder doesn't exist yet, @mkdir() tries to create it on
// the spot (the @ suppresses the warning if that fails - the resulting
// false from mkdir() is exactly what marks the requirement as failed).
$requirements = [
    ['label' => 'PHP 8.0 or newer', 'ok' => version_compare(PHP_VERSION, '8.0.0', '>='), 'detail' => 'Detected PHP ' . PHP_VERSION],
    ['label' => 'PDO MySQL extension', 'ok' => extension_loaded('pdo_mysql'), 'detail' => extension_loaded('pdo_mysql') ? 'Enabled' : 'Missing - enable pdo_mysql in php.ini'],
    ['label' => 'cURL extension (PayPal/Stripe)', 'ok' => extension_loaded('curl'), 'detail' => extension_loaded('curl') ? 'Enabled' : 'Missing - card/PayPal checkout will fall back to pending-only'],
    ['label' => 'GD extension (image cropping)', 'ok' => extension_loaded('gd'), 'detail' => extension_loaded('gd') ? 'Enabled' : 'Missing - product image cropping will be unavailable'],
    ['label' => 'config/ folder is writable', 'ok' => is_writable(__DIR__ . '/config'), 'detail' => __DIR__ . '/config'],
    ['label' => 'uploads/products/ folder is writable', 'ok' => is_dir(UPLOAD_DIR) ? is_writable(UPLOAD_DIR) : @mkdir(UPLOAD_DIR, 0755, true), 'detail' => UPLOAD_DIR],
];
// array_column(..., 'ok') pulls out just the true/false values from every
// requirement row; overall requirements are only satisfied if none of them
// is false.
$requirementsOk = !in_array(false, array_column($requirements, 'ok'), true);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Install - <?= e(SITE_NAME) ?></title>
  <link rel="stylesheet" href="admin/assets/css/admin.css">
</head>
<body>
<div class="login-wrap">
  <div class="login-box" style="max-width:560px;">
    <h2 style="margin-top:0;"><?= e(SITE_NAME) ?> Setup</h2>

    <?php foreach ($errors as $error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endforeach; ?>

    <?php /* One of five wizard screens is rendered below based on $step
             (computed near the top of this file from ?step= and whether
             the site is already $locked): 'locked' (already installed),
             'database' (step 2 form), 'admin' (step 3 form), 'done'
             (final confirmation), or the 'welcome'/default requirements
             checklist (step 1). */ ?>
    <?php if ($step === 'locked'): ?>

      <div class="flash flash-info">shopRex is already installed. For safety, this installer cannot run again.</div>
      <p>To reconfigure the database or reset the admin account, edit
         <code>config/installed.php</code> or your database directly.</p>
      <p style="margin-top:20px;">
        <a class="btn" href="admin/login">Go to Admin Login</a>
        <a class="btn btn-secondary" href="./">View Storefront</a>
      </p>

    <?php elseif ($step === 'database'): ?>

      <p style="color:var(--color-muted);">Step 2 of 3 &middot; Site &amp; database</p>
      <form method="post" action="install.php?step=database">
        <?= csrfField() ?>
        <div class="form-group">
          <label for="site_url">Site URL</label>
          <?php /* Re-show whatever the visitor already typed if this form
                   was redisplayed after a validation error, otherwise
                   auto-detect it from the current request (detectSiteUrl(),
                   defined in config/config.php) as a starting guess. */ ?>
          <input type="text" id="site_url" name="site_url" required value="<?= e($old['siteUrl'] ?? detectSiteUrl()) ?>">
          <small style="color:var(--color-muted);">
            Detected automatically from this request, including any subdirectory (e.g.
            <code>http://localhost/shopRex</code>) - correct it if it's wrong, e.g. behind a reverse proxy.
            No trailing slash. Every link/redirect/email the app generates is built from this, so it must
            match exactly how visitors reach the site. Editable later from Admin &rarr; Settings.
          </small>
        </div>
        <div class="form-grid">
          <div class="form-group"><label for="db_host">Database host</label><input type="text" id="db_host" name="db_host" required value="<?= e($old['host'] ?? '127.0.0.1') ?>"></div>
          <div class="form-group"><label for="db_port">Port</label><input type="text" id="db_port" name="db_port" value="<?= e($old['port'] ?? '3306') ?>"></div>
        </div>
        <div class="form-group"><label for="db_name">Database name</label><input type="text" id="db_name" name="db_name" required value="<?= e($old['name'] ?? 'shoprex') ?>"></div>
        <div class="form-grid">
          <div class="form-group"><label for="db_user">Database user</label><input type="text" id="db_user" name="db_user" required value="<?= e($old['user'] ?? 'root') ?>"></div>
          <div class="form-group"><label for="db_pass">Database password</label><input type="password" id="db_pass" name="db_pass"></div>
        </div>
        <div class="form-group">
          <label><input type="checkbox" name="install_demo" value="1" style="width:auto;" checked> Install demo content (sample categories &amp; products)</label>
        </div>
        <p style="font-size:13px;color:var(--color-muted);">
          If the database doesn't exist yet, it will be created for you (the
          user above needs <code>CREATE DATABASE</code> privileges - or
          pre-create an empty database and grant that user access to it).
        </p>
        <button class="btn btn-block" type="submit">Create Database &amp; Continue</button>
      </form>

    <?php elseif ($step === 'admin'): ?>

      <p style="color:var(--color-muted);">Step 3 of 3 &middot; Create your administrator account</p>
      <form method="post" action="install.php?step=admin">
        <?= csrfField() ?>
        <div class="form-group"><label for="username">Username</label><input type="text" id="username" name="username" required value="<?= e($old['username'] ?? '') ?>"></div>
        <div class="form-group"><label for="email">Email address</label><input type="email" id="email" name="email" required value="<?= e($old['email'] ?? '') ?>"></div>
        <div class="form-grid">
          <div class="form-group"><label for="password">Password</label><input type="password" id="password" name="password" minlength="8" required></div>
          <div class="form-group"><label for="password_confirm">Confirm password</label><input type="password" id="password_confirm" name="password_confirm" minlength="8" required></div>
        </div>
        <p style="font-size:13px;color:var(--color-muted);">This account gets the <strong>Super Admin</strong> role - full access, including managing other admin accounts.</p>
        <button class="btn btn-block" type="submit">Create Admin Account</button>
      </form>

    <?php elseif ($step === 'done'): ?>

      <div class="flash flash-success">Setup complete!</div>
      <p>Your database is ready and your Super Admin account has been created.</p>
      <p style="margin-top:20px;">
        <a class="btn" href="admin/login">Go to Admin Login</a>
        <a class="btn btn-secondary" href="./">View Storefront</a>
      </p>
      <p style="font-size:13px;color:var(--color-muted);margin-top:20px;">
        Before going live: review <code>config/config.php</code> for mail/PayPal/Stripe
        settings, and consider removing write access to <code>install.php</code>.
      </p>

    <?php else /* welcome */: ?>

      <p style="color:var(--color-muted);">Step 1 of 3 &middot; Requirements check</p>
      <table class="data-table" style="margin-bottom:20px;">
        <tbody>
        <?php foreach ($requirements as $req): ?>
          <tr>
            <td><?= $req['ok'] ? '&#9989;' : '&#10060;' ?> <?= e($req['label']) ?></td>
            <td style="color:var(--color-muted);font-size:13px;"><?= e($req['detail']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php if (!$requirementsOk): ?>
        <div class="flash flash-error">Fix the items marked &#10060; above before continuing (a red cURL warning alone is fine to ignore for now).</div>
      <?php endif; ?>
      <a class="btn btn-block" href="install.php?step=database">Continue to Database Setup</a>

    <?php endif; ?>
  </div>
</div>
</body>
</html>
