# shopRex Security Audit

**Date:** 2026-08-14
**Scope:** Full source review of the shopRex codebase (storefront, back office, installer, payment/email/PDF/GDPR subsystems) as of commit `aa7a501` (initial public commit).
**Method:** Manual line-by-line review — authentication, session/CSRF handling, SQL construction, output encoding, file upload/download, payment gateway integration, admin RBAC, and GDPR tooling — supplemented by repo-wide pattern searches for common injection/traversal sinks. No automated scanner or dynamic/black-box testing was used.

This is not a substitute for a paid third-party penetration test before handling real customer payments, but it covers the areas most likely to matter for a framework like this.

---

## Summary

| # | Finding | Severity |
|---|---|---|
| 1 | Cart price/stock manipulation via cross-product option values | **Critical** |
| 2 | Payment capture not bound to the order it was created for | **Critical** |
| 3 | Payment capture callback is not idempotent (duplicate revenue entries) | **High** |
| 4 | `order_confirmation.php` has no access control (PII disclosure) | **High** |
| 5 | No brute-force protection on login / password reset | Medium |
| 6 | Product image uploads validated by extension only, not content | Medium |
| 7 | No Content-Security-Policy header | Low–Medium |
| 8 | `admin/order_view.php` doesn't whitelist status values server-side | Low |
| 9 | `install.php` interpolates a validated value into raw SQL | Informational |

Everything else reviewed — CSRF (`includes/functions.php`), SQL parameterization elsewhere, password hashing, session cookie flags, invoice access control, admin role separation, `.htaccess` hardening, GDPR export/erasure — held up well; see **What's already solid** at the end.

---

## 1. Cart price/stock manipulation via cross-product option values — Critical

**Where:** `includes/Cart.php`, `Cart::getItems()`, lines ~174-190.

`cart_action.php`'s `add` action accepts an arbitrary `options[optId] = valueId` array from POST and stores whatever value IDs are given, without checking they belong to the product being added:

```php
// cart_action.php - 'add' action
foreach ($_POST['options'] as $optId => $valId) {
    if ((int)$valId <= 0) { ... }
    $optionValueIds[] = (int)$valId;   // no check this value belongs to $productId
}
```

Later, `Cart::getItems()` re-hydrates each cart line for pricing. When the chosen option values don't form a real variant of the product (`Cart::findVariant()` correctly scopes by `product_id` and returns `null` for a mismatched combination), the code falls back to a per-value lookup that has **no product scoping at all**:

```php
foreach ($optionValueIds as $optionValueId) {
    $optStmt = db()->prepare(
        'SELECT ov.value, ov.price_modifier, ov.stock_quantity, po.name AS option_name
         FROM product_option_values ov
         JOIN product_options po ON po.id = ov.product_option_id
         WHERE ov.id = ?'                    // <-- missing "AND po.product_id = ?"
    );
    $optStmt->execute([$optionValueId]);
    $option = $optStmt->fetch();
    if ($option) {
        $unitPrice += (float)$option['price_modifier'];   // applied unconditionally
        ...
        $availableStock = min($availableStock, (int)$option['stock_quantity']);
    }
}
```

**Impact:** Any visitor (no account required — the cart is session-based) can add a normal product to the cart with a crafted `options[]` array pointing at an option value ID that belongs to a *different* product. If that option value has a negative `price_modifier` (a common pattern for "smaller size = cheaper"), its full modifier is subtracted from the target product's price — with no floor at zero. `line_total`/`subtotal` are never clamped to be non-negative, so a large enough negative modifier drives the order total toward zero or negative. The same lookup also lets an attacker substitute a foreign option value's `stock_quantity` to bypass an out-of-stock or low-stock limit on the real product. `checkout_process.php` re-derives everything from `Cart::getItems()` server-side (correct in principle — it never trusts client-submitted prices), but because the *server-side* computation itself is unscoped, that re-derivation doesn't help here.

**Proof-of-concept sketch:**
1. Find any `product_option_values.id` in the shop with a large negative `price_modifier` (or just a cheap product's option value) and note its ID, e.g. `id=17`.
2. `POST cart_action.php` with `action=add`, `product_id=<expensive product>`, `options[1]=17`, valid CSRF token.
3. View `cart.php` — the expensive product's unit price now includes the unrelated option's modifier.
4. Complete checkout (e.g. via bank transfer, which requires no gateway interaction) at the manipulated price.

**Fix:** Add `AND po.product_id = ?` to the query in `Cart::getItems()` and bind `$product['id']`; skip/reject any `optionValueId` that doesn't come back. Additionally, validate in `cart_action.php`'s `add` action that every submitted `option_value_id` actually belongs to one of the product's own `product_options` before accepting it into the cart at all (defense in depth — don't rely solely on the read-time fix). Also clamp `$unitPrice`/`$lineTotal` to `max(0, ...)` as a backstop.

---

## 2. Payment capture not bound to the order it was created for — Critical

**Where:** `checkout_process.php`, `handleCapture()`, lines ~260-284.

The gateway-return endpoint (reached via a plain `GET`, since PayPal/Stripe redirect the browser back and no CSRF token is available) resolves *which order to mark paid* purely from the `order` query parameter, and *which payment to capture/verify* purely from `token` (PayPal) / `session_id` (Stripe) — also just query parameters:

```php
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'capture') {
    handleCapture($_GET['gateway'] ?? '', $_GET['order'] ?? '', $_GET['session_id'] ?? null);
}
...
function handleCapture(string $gateway, string $orderNumber, ?string $sessionId): void
{
    $order = fetchOrderByNumber($orderNumber);          // attacker-chosen order
    if ($gateway === 'paypal') {
        $paypalOrderId = $_GET['token'] ?? null;         // attacker-chosen token
        ...
        if (($captureResponse['status'] ?? '') === 'COMPLETED') {
            markOrderPaid($order, $txId, ...);            // marks the *substituted* order paid
        }
    } elseif ($gateway === 'credit_card' && $sessionId) {
        $sessionData = fetchStripeSession($sessionId);
        if (($sessionData['payment_status'] ?? '') === 'paid') {
            markOrderPaid($order, $sessionId, ...);
        }
    }
}
```

Nothing ties the PayPal `token`/Stripe `session_id` to the specific `order` in the URL — there's no comparison against the `transaction_id` that was stored on that order at `start()` time, and no comparison of the captured amount against `order['total']`.

**Impact:** An attacker can legitimately create and pay for a cheap order (say €1) via PayPal or Stripe, obtain the real, valid `token`/`session_id` for that completed payment, then call `checkout_process.php` with `gateway=paypal&order=<a-different-order-number>&token=<their-real-token>&action=capture` (or the Stripe equivalent). Because the token/session really is valid and really is "paid"/"COMPLETED", the *substituted* order — which can be any order number the attacker knows, including one of their own high-value orders — gets marked `payment_status = paid` for its full amount, without the corresponding money ever being paid for it.

**Fix:** When `PayPalGateway::start()` / `CreditCardGateway::start()` create the gateway-side order/session, store an unambiguous binding (PayPal: use the stored `payments.transaction_id` as the *only* source of truth for capture, ignoring `$_GET['token']` for anything except as a fallback UI hint; Stripe: set `client_reference_id` or `metadata.order_number` on session creation and verify it matches `$orderNumber` on return, in addition to already using the stored session id). In both cases, additionally verify the captured/paid **amount** equals `order['total']` (and currency) before calling `markOrderPaid()`, and reject the capture otherwise.

---

## 3. Payment capture callback is not idempotent — High

**Where:** `checkout_process.php`, `markOrderPaid()`, lines ~244-258.

`markOrderPaid()` unconditionally runs an `UPDATE` and an `INSERT INTO transactions (...)` every time it's called — there's no check for `$order['payment_status'] !== 'paid'` first (contrast with `admin/order_view.php`, which *does* guard its own call to the same pattern: `if ($newPaymentStatus === 'paid' && $order['payment_status'] !== 'paid')`).

**Impact:** For the Stripe path in particular, `fetchStripeSession($sessionId)` is a read-only status check with no server-side state change — simply reloading, back-buttoning, or otherwise revisiting the `checkout_process.php?...&action=capture&session_id=...` return URL after a successful payment re-triggers `markOrderPaid()` and inserts *another* row into `transactions` for the same order's full amount, each time. This happens even without any malicious intent (slow network causing a retried request, a customer using the browser back/forward buttons) and inflates `admin/finance.php`'s revenue figures and the dashboard's revenue cards.

**Fix:** Guard the whole function (or its call sites) with the same `payment_status !== 'paid'` check used elsewhere; consider also making the `orders`/`payments` update idempotent by keying off `payments.transaction_id` (only accept a transition when the transaction id hasn't already been recorded as completed).

---

## 4. `order_confirmation.php` has no access control — High

**Where:** `order_confirmation.php`, lines 1-20.

```php
$orderNumber = $_GET['order'] ?? '';
$stmt = db()->prepare("SELECT o.*, COALESCE(c.email, o.guest_email) AS customer_email
                        FROM orders o LEFT JOIN customers c ON c.id = o.customer_id
                        WHERE o.order_number = ?");
$stmt->execute([$orderNumber]);
$order = $stmt->fetch();
// ...no ownership or admin check before rendering...
```

The page then renders the customer's full name, shipping address, email, itemized purchases, and totals — with **no check** that the requester is the order's owner or an admin. Compare this with `invoice_download.php`, which correctly checks `isOwner || isAdmin` before serving anything.

Order numbers are `'SR' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)))` — only **3 random bytes (24 bits, ~16.7M values) per day**, with a fully predictable date prefix. That's small enough to be brute-forced by an automated script well within a day against a live site, and the exposure isn't limited to brute force: the URL is also what every customer's browser is auto-redirected to (and lands in browser history) right after checkout, so it can leak via shared links, proxy/analytics logs, or a shoulder-surfed screenshot.

**Impact:** Unauthenticated disclosure of another customer's name, full shipping address, email address, and order contents/spend.

**Fix:** Require the same `isOwner || isAdmin` check `invoice_download.php` already uses, before rendering order details. For the one legitimate unauthenticated use case — the guest who just checked out and has no account — either keep a short-lived, single-use, cryptographically random confirmation token (separate from the human-facing order number) or tie the confirmation page's session to the one that just placed the order (e.g., a one-time `$_SESSION['last_order_id']` set at the end of `checkout_process.php`) rather than trusting the URL alone.

---

## 5. No brute-force protection on login / password reset — Medium

**Where:** `login.php`, `admin/login.php`, `forgot_password.php`.

None of these endpoints implement rate limiting, account lockout, or a CAPTCHA/proof-of-work challenge after repeated failures. `admin/login.php` in particular is a high-value target — a compromised admin account bypasses every other control in this report.

**Fix:** At minimum, add a per-IP and/or per-username throttle (e.g., exponential backoff or a fixed lockout window after N failures within a period, logged so an admin can see it) on `login.php`, `admin/login.php`, and `forgot_password.php`. A dependency-free approach is a `login_attempts` table keyed by IP+username with a timestamp, checked before `password_verify()` runs.

---

## 6. Product image uploads validated by extension only, not content — Medium

**Where:** `admin/product_images.php`, lines 27-51.

```php
$allowed = ['jpg' => true, 'jpeg' => true, 'png' => true, 'webp' => true, 'gif' => true];
$ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
if (!isset($allowed[$ext]) || $_FILES['image']['size'] > 8 * 1024 * 1024) { ... }
else {
    // saved as-is with move_uploaded_file() - no getimagesize()/finfo check on content
}
```

The extension is whitelisted, but the file's actual content is never verified to be a real image before it's written under `uploads/products/`. The only thing standing between an uploaded file with attacker-controlled bytes and code execution is the `uploads/.htaccess` rule blocking PHP execution in that directory — which is Apache-only, requires `AllowOverride` to honor `.htaccess` at all, and is explicitly called out in the README as *not* automatically ported if the site is later served from nginx. This upload endpoint is reachable by the **Manager** role, not just Super Admin, so it's a real concern for the lower-trust account tier the app deliberately carves out.

**Fix:** Call `getimagesize($_FILES['image']['tmp_name'])` (or `finfo_file()` with `FILEINFO_MIME_TYPE`) and reject the upload if it doesn't report a real, matching image type — the same check `ImageProcessor::cropAndSave()` already does for the crop step, just moved earlier to also cover the initial upload. This doesn't replace the `.htaccess` hardening, it backstops it.

---

## 7. No Content-Security-Policy header — Low–Medium

**Where:** `.htaccess` (root), `config/config.php`.

The project sends `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, and `Permissions-Policy`, but no `Content-Security-Policy`. This matters more than usual here because the app has two *intentionally* trusted-HTML surfaces documented in the README itself: CMS page content (`page.php`) and email templates, both editable by any admin including the lower-trust Manager role for pages. A CSP wouldn't change the accepted trust model, but it would meaningfully limit the blast radius (e.g., blocking exfiltration to arbitrary domains, restricting inline script execution) if a Manager account is ever compromised or a Manager intentionally abuses page-editing access.

**Fix:** Add a CSP allow-listing the CDN hosts already in use (`cdn.jsdelivr.net`, `code.jquery.com`) plus `'self'`, e.g. via `Header always set Content-Security-Policy "default-src 'self'; script-src 'self' cdn.jsdelivr.net code.jquery.com; style-src 'self' 'unsafe-inline' cdn.jsdelivr.net; img-src 'self' data:; frame-ancestors 'self'"` — tune against actual usage before enabling, since Bootstrap/jQuery UI's inline styles will likely need `'unsafe-inline'` for `style-src` unless self-hosted.

---

## 8. `admin/order_view.php` doesn't whitelist status values server-side — Low

**Where:** `admin/order_view.php`, lines 19-22.

```php
$newStatus = $_POST['status'] ?? $order['status'];
$newPaymentStatus = $_POST['payment_status'] ?? $order['payment_status'];
```

`$statuses`/`$paymentStatuses` arrays exist and constrain the rendered `<select>`, but the POST handler never checks the submitted value against them before writing to the database. Impact is low since this endpoint already requires the Super-Admin-only `orders` capability, but a malformed/unexpected value here could produce a DB error (if the column is a strict `ENUM`) or an inconsistent status string that other pages don't expect.

**Fix:** `in_array($_POST['status'] ?? '', $statuses, true) ? $_POST['status'] : $order['status']` (same pattern already used correctly in `admin/admins.php` for `role`/`status`).

---

## 9. `install.php` interpolates a validated value into raw SQL — Informational

**Where:** `install.php`, lines 126-142.

```php
if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
    $errors[] = 'Database name may only contain letters, numbers, and underscores.';
}
...
$pdo->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `$name`");
```

Not currently exploitable — `$name` is validated against a strict whitelist regex before use, and identifiers can't be bound as PDO parameters anyway (this is the standard pattern for a "create database" step). Flagging only so the regex guard and the interpolation stay paired if this code is ever touched — don't loosen the regex without also parameterizing or re-validating.

---

## What's already solid

- **CSRF**: `verifyCsrf()` correctly guards against the `hash_equals('', '')`-returns-`true` footgun by requiring both tokens non-empty first; used consistently on every state-changing form and AJAX endpoint checked, including the two jQuery-UI reorder endpoints (`admin/menu_reorder.php`, `admin/product_image_reorder.php`), which also independently re-check `adminCan()`.
- **SQL injection**: every query reviewed outside of the two items above uses parameterized `PDO` prepared statements; the few places building SQL strings dynamically (`index.php`/`search.php` sort/filter, `admin/*` list pages) only interpolate whitelisted column-name maps or validated integers, never raw user input.
- **XSS**: `e()` (`htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`) is used consistently across every template file reviewed; the one deliberately-unescaped surface (CMS page content) is explicitly documented as trusted-admin HTML, matching the WordPress-style trust model.
- **Password handling**: `password_hash()`/`password_verify()` (bcrypt) throughout; reset tokens use `random_bytes(32)` with a 1-hour expiry and are cleared on use; login/registration call `regenerateSession()` (session fixation defense) before setting the identity session key.
- **Session cookie**: `HttpOnly`, `SameSite=Lax`, `Secure` when served over HTTPS.
- **Invoice access control**: `invoice_download.php` correctly checks order ownership or admin session before streaming a PDF (contrast with finding #4).
- **Admin RBAC**: capability map is centralized (`admin/includes/roles.php`) and consistently enforced (`requireAdminPermission()` at the top of every admin page checked); `admin/admins.php` correctly blocks self-demotion/deletion of the last active Super Admin and self-deletion.
- **File-serving hardening**: `.htaccess` files correctly deny direct web access to `config/`, `includes/`, `sql/`, `admin/cron/`, and `uploads/invoices/`, and block PHP execution under `uploads/`; the CLI-only GDPR cron script (`admin/cron/gdpr_cleanup.php`) refuses to run over HTTP as a second, code-level check.
- **GDPR tooling**: export/erasure (`includes/GdprTools.php`) is implemented consistently for both the customer self-service and admin-triggered paths, with orders correctly retained-but-scrubbed rather than deleted outright.

---

## Recommended priority order

1. Fix #1 (cart price manipulation) and #2 (payment capture confusion) before this handles any real payment — both allow taking payment for less than the actual order total, or none at all.
2. Fix #3 (idempotency) and #4 (order confirmation access control) next — both are straightforward, low-risk patches.
3. Add rate limiting (#5) and upload content validation (#6) before a public launch.
4. #7-#9 are worthwhile hardening but not urgent.
