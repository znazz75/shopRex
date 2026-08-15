<?php
/**
 * shopRex installer.
 *
 * First-run setup wizard: checks server requirements, collects database
 * connection details (creating the database + importing the schema),
 * then creates the first Super Admin account. Once an admin account
 * exists, this script permanently refuses to run again - re-installing
 * over a live shop would wipe/duplicate data.
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';

/**
 * True once config/installed.php exists AND at least one admin account
 * has been created - i.e. setup fully completed.
 */
function installationIsComplete(): bool
{
    if (!IS_INSTALLED) {
        return false;
    }
    try {
        require_once __DIR__ . '/config/database.php';
        $count = Database::getConnection()->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
        return (int)$count > 0;
    } catch (Throwable $e) {
        return false; // DB unreachable or table missing - treat as not installed
    }
}

$locked = installationIsComplete();
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
    $inString = false;
    $length = strlen($sql);

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
                $inString = !$inString;
            }
        } elseif ($char === ';' && !$inString) {
            $statements[] = trim($current, "; \t\n\r\0\x0B");
            $current = '';
        }
    }

    $tail = trim($current);
    if ($tail !== '') {
        $statements[] = $tail;
    }

    return array_filter($statements, fn($s) => $s !== '');
}

function runSqlFile(PDO $pdo, string $path): array
{
    $sql = file_get_contents($path);
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
    requireCsrf();

    $host = trim($_POST['db_host'] ?? '');
    $port = trim($_POST['db_port'] ?? '3306');
    $name = trim($_POST['db_name'] ?? '');
    $user = trim($_POST['db_user'] ?? '');
    $pass = $_POST['db_pass'] ?? '';
    $siteUrl = rtrim(trim($_POST['site_url'] ?? ''), '/');
    $installDemo = !empty($_POST['install_demo']);
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
            $pdo = new PDO(
                "mysql:host=$host;port=$port;charset=utf8mb4",
                $user,
                $pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
            );
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `$name`");

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

    if (!IS_INSTALLED) {
        redirect('install.php');
    }
    require_once __DIR__ . '/config/database.php';
    $pdo = Database::getConnection();

    if ((int)$pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn() > 0) {
        redirect('install.php'); // already completed elsewhere - don't create a second one
    }

    $username = trim($_POST['username'] ?? '');
    $email = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);
    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';
    $old = ['username' => $username, 'email' => $_POST['email'] ?? ''];

    if ($username === '') $errors[] = 'Username is required.';
    if (!$email) $errors[] = 'A valid email address is required.';
    if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
    if ($password !== $passwordConfirm) $errors[] = 'Passwords do not match.';

    if (!$errors) {
        $stmt = $pdo->prepare(
            'INSERT INTO admin_users (username, email, password_hash, role, status) VALUES (?, ?, ?, "super_admin", "active")'
        );
        $stmt->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT)]);
        $pdo->prepare('UPDATE settings SET setting_value = ? WHERE setting_key = "shop_email"')->execute([$email]);
        redirect('install.php?step=done');
    }
}

// Requirement checks shown on the welcome step
$requirements = [
    ['label' => 'PHP 8.0 or newer', 'ok' => version_compare(PHP_VERSION, '8.0.0', '>='), 'detail' => 'Detected PHP ' . PHP_VERSION],
    ['label' => 'PDO MySQL extension', 'ok' => extension_loaded('pdo_mysql'), 'detail' => extension_loaded('pdo_mysql') ? 'Enabled' : 'Missing - enable pdo_mysql in php.ini'],
    ['label' => 'cURL extension (PayPal/Stripe)', 'ok' => extension_loaded('curl'), 'detail' => extension_loaded('curl') ? 'Enabled' : 'Missing - card/PayPal checkout will fall back to pending-only'],
    ['label' => 'GD extension (image cropping)', 'ok' => extension_loaded('gd'), 'detail' => extension_loaded('gd') ? 'Enabled' : 'Missing - product image cropping will be unavailable'],
    ['label' => 'config/ folder is writable', 'ok' => is_writable(__DIR__ . '/config'), 'detail' => __DIR__ . '/config'],
    ['label' => 'uploads/products/ folder is writable', 'ok' => is_dir(UPLOAD_DIR) ? is_writable(UPLOAD_DIR) : @mkdir(UPLOAD_DIR, 0755, true), 'detail' => UPLOAD_DIR],
];
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
