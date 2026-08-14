# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

shopRex is a plain-PHP + MySQL online shop framework — procedural/light-OOP,
PDO-based, **zero Composer/npm dependencies to run**. It's a starting point
meant to be extended, not a finished product (see README.md's "Notes / known
simplifications" for what's deliberately left simple). Storefront + admin
back office, trilingual (EN/DE/FR) out of the box, PayPal/Stripe/bank
transfer checkout with PDF invoices.

## Commands

There is no build step, package manager, or automated test suite.

- **Run locally**: `php -S localhost:8000` from the project root, or serve
  it under Apache (XAMPP/MAMP/etc. — required for `.htaccess` security
  rules to take effect; nginx needs those rules ported into the server
  block manually, they don't apply automatically).
- **Lint a file after editing it**: `php -l path/to/file.php` — do this on
  every PHP file you touch; it's the only automated check in this repo.
- **Database**: either let `install.php` create/import it on first run
  (the normal path), or manually: `mysql -u root -p your_db < sql/schema.sql`
  (+ optionally `sql/seed_demo.sql` for sample categories/products), then
  set the `SHOPREX_DB_*`/`SHOPREX_SITE_URL` env vars or write
  `config/installed.php` by hand, and insert your own `admin_users` row.
- **Verifying a change actually works** means clicking through it in a
  browser end-to-end (storefront and/or `/admin`) — there's no test suite
  to lean on instead.

## Versioning (do this on every change)

This project uses a non-semver decimal scheme: **every change bumps the
version by exactly `0.01`** (`1.00` → `1.01` → `1.02` → …). On any change:
1. Bump the version string in both `VERSION` (repo root) and the
   `SHOPREX_VERSION` constant in `config/config.php`.
2. Add a dated entry to `CHANGELOG.md`.
3. Tag the commit `vX.XX` and push the tag (`git tag -a vX.XX -m "..." && git push origin vX.XX`) — this is what puts a downloadable source archive on GitHub Releases. Consider also `gh release create vX.XX` with notes for anything beyond a trivial bump.

Full detail in `CONTRIBUTING.md`'s "Versioning" section.

## Architecture

**No router, no framework.** Every top-level `.php` file is a directly
web-accessible page (`index.php`, `product.php`, `cart.php`,
`checkout.php`, ...). Each one starts with
`require_once __DIR__ . '/includes/bootstrap.php'` (storefront) or
`require_once __DIR__ . '/includes/bootstrap.php'` (admin pages, from
`admin/`, which chains to `admin/includes/bootstrap.php`). Admin pages
additionally call `requireAdminPermission('capability')` as their second
line — see "Admin RBAC" below.

**`includes/functions.php`** is the central helper module almost
everything depends on: `db()` (PDO singleton), `e()` (HTML-escape), CSRF
(`csrfField()`/`requireCsrf()`/`verifyCsrf()`), settings (`getSetting()` —
reads the `settings` key/value table, cached per-request in a static var
inside the function, so **a setting written earlier in the same request
won't be seen by a later `getSetting()` call in that same request** unless
you thread the new value through explicitly rather than re-reading it),
category/menu tree builders, theme resolution, discount/availability-window
logic, and the product/option translation overlay functions (see below).

**Themes** (`themes/<key>/`): a *layout package* (structurally different
storefront, not just colors) is auto-discovered by the presence of
`theme.json`; it can override any of `header.php`/`footer.php`/`home.php`,
falling back to `includes/*.php` for whatever it doesn't provide.
Resolved per-request by `themeTemplatePath()` — every storefront page
calls this instead of requiring `includes/header.php` directly. This is
independent of the `THEMES` **color accent** constant in
`includes/functions.php` (just recolors CSS variables within whichever
layout is active). Same auto-discovery pattern is used for languages
(`includes/lang/*.php`, see below).

**Admin RBAC** (`admin/includes/roles.php`): two roles, `super_admin`
(everything) and `manager` (products/categories/inventory/pages/menus
only). `ADMIN_CAPABILITIES` maps a capability string to which roles have
it; `requireAdminPermission('capability')` is the gate every admin page
opens with. Adding a role/capability means editing that one file plus the
`role` column's `ENUM` in `sql/schema.sql`.

**i18n** (`includes/i18n.php` + `includes/lang/{en,de,fr}.php`): each lang
file returns a flat `'namespace.key' => 'string'` array (640 keys, kept in
sync across all three); `__('key', ['token' => $val])` looks up the
current language and falls back to English. Two distinct language sets
matter:
- `getAvailableLanguages()` — every `includes/lang/*.php` file that
  exists on disk.
- `getEnabledLanguages()` — the subset an admin has actually enabled
  (Admin → Settings → Languages, backed by the `enabled_languages`
  setting); this is what `getCurrentLanguage()`, every language picker,
  and the per-language tabs in Pages/Email Templates/Categories/Products
  all use. **Use `getEnabledLanguages()`, not `getAvailableLanguages()`,
  for anything user-facing** — the latter is only for the settings screen
  that lets you re-enable a currently-disabled language.

Non-English month names for `formatLocalDate()` are spelled out by hand
per-language (PHP's `date()` isn't locale-aware) — see the `de`/`fr`
branches in `includes/i18n.php` for the pattern to follow when adding one.

**Product/option translation** is a separate mechanism from the UI-chrome
i18n above: `products`/`product_options`/`product_option_values` always
hold the *default-language* content; every other language lives in a
sibling `product_translations`/`product_option_translations`/
`product_option_value_translations` row, overlaid at read time by
`applyProductTranslation()`/`applyOptionTranslations()` in
`includes/functions.php`. Because `product_options`/`product_option_values`
are fully deleted and recreated on every product save
(`admin/product_edit.php`), that page's translation inputs for *every*
language are always present in the form (just CSS-hidden for inactive
tabs) so they get resubmitted and re-inserted on every save — don't
"optimize" that away or translations will vanish on the next edit that
doesn't touch them. Category *names* are NOT translated this way (only a
category's `intro_text` is per-language); CMS pages (`pages` table) use a
third pattern again — one whole row per (slug, language).

**Payment gateways** (`includes/PaymentGateway.php`): one `PaymentGateway`
interface, four implementations (PayPal/CreditCard/BankTransfer/Invoice)
plus `TestGateway` for `is_test_account` customers (no network calls,
auto-completes). `checkout_process.php`'s `handleCapture()` is the
PayPal/Stripe return-URL handler — it only ever captures using the
transaction_id/session_id already stored on that order's `payments` row
(never a client-supplied `$_GET` value) and verifies the captured amount
matches the order total before marking anything paid; don't reintroduce
trusting the URL directly, see `docs/SECURITY_AUDIT.md` finding #2 for why.

**Cart** (`includes/Cart.php`): session-based (`$_SESSION['cart']`),
rehydrated from the DB on every read via `Cart::getItems()` so prices/stock
are always current. Stock for a product with options is tracked per exact
combination (`product_variants`/`product_variant_values`), not per single
option value. When resolving an option value's price modifier, the query
**must** scope by `product_option_id`'s `product_id` — an unscoped lookup
lets a crafted option-value ID from a *different* product apply its price
modifier here (was CVE-shaped, see `docs/SECURITY_AUDIT.md` finding #1).

**Test accounts** (`is_test_account` on `customers`): orders placed while
logged in as one use `TestGateway`, are logged to `inventory_log` but never
decrement real stock, and are tagged `is_test_order = 1` — excluded from
every financial figure in `admin/finance.php` and the dashboard. Created
via Admin → Customers → Create Test User.

**Settings** live in the `settings` key/value table, read via
`getSetting($key, $default)`. Not every setting has a seeded row in
`sql/schema.sql` (e.g. `enabled_languages`, `site_theme_package`) — those
use the upsert (`INSERT ... ON DUPLICATE KEY UPDATE`) pattern rather than
the seeded-row `UPDATE` pattern when saving; check `admin/settings.php` for
which pattern an existing setting uses before copying it.

## Security posture

CSRF (`csrfField()`/`requireCsrf()`), prepared statements throughout,
bcrypt password hashing, session-fixation defenses on login
(`regenerateSession()`), and `.htaccess` hardening (CSP + security headers,
blocked direct access to `config/`/`includes/`/`sql/`, blocked PHP
execution under `uploads/`) are already in place — see README.md's
"Security" section for specifics. `docs/SECURITY_AUDIT.md` has the full
audit and what it fixed; treat that file as required reading before
touching cart pricing, payment capture, order confirmation access control,
or file uploads, since those are exactly the areas that have already had
real bugs found and fixed once.

CMS page content (`page.php`) is rendered as **trusted, unescaped HTML** by
design (same model as WordPress page content) — anyone who can edit Pages
(Super Admin or Manager) can inject markup/scripts into the storefront.
This is a documented, intentional trust boundary, not a bug to fix.
