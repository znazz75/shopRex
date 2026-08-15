# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

shopRex is a plain-PHP + MySQL online shop framework — OOP, PDO-based,
**zero Composer/npm dependencies to run**. It's a starting point meant to
be extended, not a finished product (see README.md's "Notes / known
simplifications" for what's deliberately left simple). Storefront + admin
back office, trilingual (EN/DE/FR) out of the box, PayPal/Stripe/bank
transfer checkout with PDF invoices, plus a legal/compliance domain
(contact form, right of withdrawal, RMA/warranty tickets, legal document
management).

## Commands

There is no build step, package manager, or automated test suite.

- **Run locally**: `php -S localhost:8000` from the project root, or serve
  it under Apache (XAMPP/MAMP/etc. — required for `.htaccess` security
  rules, including the front-controller rewrite, to take effect; nginx
  needs those rules ported into the server block manually, they don't
  apply automatically).
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

Full detail in `CONTRIBUTING.md`'s "Versioning" section, including the
sanctioned one-time exception for v2.00 (the OOP rewrite below).

## Architecture

**Router, not physical files.** `index.php` (storefront) and
`admin/index.php` are the only two web-accessible entry points; both are
two-line front controllers that build a `Container` (`src/container.php`
via `src/bootstrap.php`), register a route table
(`src/routes/web.php`/`admin.php`), and hand off to `Core\App::run()`.
Every other request either serves a real file/directory as-is (uploads,
theme assets, admin assets — see the root `.htaccess`'s rewrite block) or
is dispatched by `Core\Router` to a `Controllers\Storefront\*`/
`Controllers\Admin\*` action. Adding a page is one `$router->get(...)`/
`->post(...)` line, not a new file. Clean URLs only (`/product/{slug}`,
`/category/{slug}`, `/admin/orders/{id}`, ...) — no `.php`-suffixed paths
or `?slug=`/`?category=<id>`-style query strings anywhere, including
internally-generated links (nav, breadcrumbs, emails, PDFs, redirects).

**`src/` layout**: `Core/` (Router, Route, Request, Response, Renderer,
ThemeManager, Container, Model, Controller/AdminController, Csrf,
FlashBag, Session, `Auth/AdminAuth`, `Auth/CustomerAuth`), `Models/`
(Product, Category, Cart, Order, Customer, ShippingMethod, TaxRate,
MenuItem, Page, `CustomerRequest` abstract base + `WithdrawalRequest`/
`RmaTicket`, ContactMessage, LegalDocument, ...), `Services/`
(CategoryTreeService, MenuTreeService, TaxCalculator, DiscountCalculator,
ShippingCalculator, TranslationOverlay, CheckoutService, InvoiceService,
Mailer, GdprService, RateLimiter, SettingsRepository, I18n,
PdfDocumentGenerator, PerPageResolver, ...), `Payment/` (`PaymentGateway`
interface, `CapturableGateway` interface, PayPal/CreditCard/BankTransfer/
Invoice/Test implementations, `PaymentGatewayFactory`), `Controllers/
Storefront/` + `Controllers/Admin/`, `Views/storefront/` + `Views/admin/`
(one `.php` per page, `extract()`-into-scope templates — same authoring
style as before, via the global helpers in `view-helpers.php`),
`Support/` (presentation-only static renderers: `Pagination`,
`StorefrontMenuRenderer`, `MenuAdminTreeRenderer`, `Slugger`), `routes/`
(`web.php`, `admin.php`). A hand-rolled PSR-4-ish autoloader
(`spl_autoload_register` in `src/bootstrap.php`) maps `ShopRex\Foo\Bar` to
`src/Foo/Bar.php` — no Composer. **`src/` is blocked from direct web
access** (`src/.htaccess`, same posture as `config/`/`includes/`/`sql/`)
since every file in it assumes it's reached through the front
controller/autoloader, not requested directly.

**`Core\Model`** is a lightweight ActiveRecord (`find()`, `save()`,
`delete()`, `fill()`/`toRow()` for snake_case↔camelCase), not a query
builder — concrete models keep bespoke, prepared-SQL finder methods,
matching this project's existing "no framework magic" style. Admin CRUD
controllers mostly work with plain arrays from `fetchAll()` rather than
hydrated models (matches the original's own patterns; only where a real
object's behavior earns its keep — Order, Cart, CustomerRequest — is one
used).

**`Core\Auth\AdminAuth`**: two roles, `super_admin` (everything) and
`manager` (products/categories/inventory/pages/menus only).
`AdminAuth::CAPABILITIES` maps a capability string to which roles have
it; a route's `->capability('x')` is the gate the `Router` checks before
dispatch (redirects to `/admin/login` or "no access" otherwise) —
`AdminController`'s constructor re-checks login as defense-in-depth in
case a route is ever registered without `->capability()`. Adding a
role/capability means editing that one class plus the `role` column's
`ENUM` in `sql/schema.sql`.

**Themes** (`themes/<key>/`): a *layout package* (structurally different
storefront, not just colors) is still auto-discovered by the presence of
`theme.json`, and its web-servable static asset (`style.css`) still lives
here — but its PHP templates (`header.php`/`footer.php`/`home.php`) live
under `src/Views/storefront/theme/<key>/` instead (blocked from direct
access, like the rest of `src/`), falling back to `.../theme/default/`
for whatever a package doesn't override. Resolved per-request by
`Core\ThemeManager::resolve()`, called from `Core\Renderer::render()`/
`renderSlot()` — every storefront controller renders through one of
those rather than requiring a header file directly. Admin has one fixed
layout (`src/Views/admin/layout/`), no package mechanism — but Admin →
Settings' theme-package picker still needs the *storefront's*
`ThemeManager` even on an admin request, so `src/container.php` binds a
second, always-storefront-flavored instance as `'ThemeManager.storefront'`
(don't resolve `ThemeManager::class` for that picker on an admin
controller — it's the fixed-layout, no-packages instance there). This is
independent of the `THEMES` color-accent array (a plain array baked into
`view-helpers.php`'s `getActiveTheme()` shim and
`SettingsAdminController::colorThemes()` — just recolors CSS variables
within whichever layout is active). Same auto-discovery pattern is used
for languages (`includes/lang/*.php`, see below).

**Tier-2 compatibility shim** (`src/view-helpers.php`, required once from
`src/bootstrap.php`): a deliberate, permanent part of the view-authoring
convention, not a migration crutch — every view (`src/Views/**/*.php`,
old and new alike) is a plain `extract()`-into-scope template calling
global functions like `e()`, `__()`, `formatPrice()`, `csrfField()`,
`getSetting()`, `getCategoryUrl()`, `resolveMenuUrl()`, `currentCustomer()`,
`renderPagination()`, etc. — these delegate to the real `Services`/`Models`
classes (e.g. `getSetting()` → `Registry::container()->make(SettingsRepository::class)->get(...)`).
Add a new one here rather than reaching into `Registry::container()`
directly from a view.

**Legacy classes kept as-is** (`includes/Cart.php`, `Mailer.php`,
`InvoiceGenerator.php`, `SimplePdf.php`, `ImageProcessor.php`,
`GdprTools.php`, `GdprCleanup.php` — loaded via `require_once` in
`src/container.php`, see that file's docblock): these
were already proper, single-purpose classes before the rewrite (not the
procedural page-level functions the rewrite targeted), so converting them
to the `ShopRex\` namespace is deferred polish, not a correctness gap.
`Services\CheckoutService` and the `Payment\*Gateway` classes call their
existing static entry points (`InvoiceService::generateForOrder()` is a
thin new wrapper; `Mailer::send()`/`::render()` are called directly).
`includes/functions.php` is pruned to exactly the handful of functions
these classes plus the standalone `install.php` (which runs before the
`src/` autoloader's dependencies are guaranteed to exist) still call —
`db()`, `e()`, `formatPrice()`, `getSetting()`, CSRF (`csrfField()`/
`requireCsrf()`/`csrfToken()`/`verifyCsrf()` — a separate implementation
from `Core\Csrf`, bound to the same `$_SESSION['csrf_token']` key so a
token from one verifies against the other), `writeInstalledConfigFile()`,
`redirect()`, and the discount/tax/translation/image helpers `Cart.php`/
`InvoiceGenerator.php` call directly (`applyProductTranslation()`,
`getActiveDiscount()`, `getEffectivePrice()`, `vatIsEnabled()`,
`getTaxRatePercent()`, `formatTaxRateNumber()`, `getPrimaryImage()`). If
you add a new call site to one of the kept legacy classes, don't
casually delete a functions.php function that looks unused without
checking those files too.

**i18n** (`includes/i18n.php` + `includes/lang/{en,de,fr}.php`, wrapped by
`Services\I18n::boot()`/`::t()`/`::current()` etc.): each lang file
returns a flat `'namespace.key' => 'string'` array (kept in exact key-set
sync across all three — check with a quick `array_diff` of `array_keys()`
before considering an i18n change done), `__('key', ['token' => $val])`
looks up the current language and falls back to English. Two distinct
language sets matter:
- `I18n::availableLanguages()` — every `includes/lang/*.php` file that
  exists on disk.
- `I18n::enabledLanguages()` — the subset an admin has actually enabled
  (Admin → Settings → Languages, backed by the `enabled_languages`
  setting); this is what `I18n::current()`, every language picker, and
  the per-language tabs in Pages/Email Templates/Categories/Products all
  use. **Use `enabledLanguages()`, not `availableLanguages()`, for
  anything user-facing** — the latter is only for the settings screen
  that lets you re-enable a currently-disabled language.

Non-English month names for `formatLocalDate()` are spelled out by hand
per-language (PHP's `date()` isn't locale-aware) — see the `de`/`fr`
branches in `includes/i18n.php` for the pattern to follow when adding one.

**Product/option translation** (`Services\TranslationOverlay`) is a
separate mechanism from the UI-chrome i18n above: `products`/
`product_options`/`product_option_values` always hold the
*default-language* content; every other language lives in a sibling
`product_translations`/`product_option_translations`/
`product_option_value_translations` row, overlaid at read time.
`Cart.php` (kept legacy class) still calls the standalone
`applyProductTranslation()` function directly rather than the service, to
re-derive a translated name/description for whatever's already in
`$_SESSION['cart']` on every read. Because `product_options`/
`product_option_values` are fully deleted and recreated on every product
save (`Controllers\Admin\ProductEditController`), that controller's
translation inputs for *every* language are always present in the form
(just CSS-hidden for inactive tabs) so they get resubmitted and
re-inserted on every save — don't "optimize" that away or translations
will vanish on the next edit that doesn't touch them. Category *names*
are NOT translated this way (only a category's `intro_text` is
per-language); CMS pages (`pages` table) use a third pattern again — one
whole row per (slug, language).

**Payment gateways** (`Payment\*`): one `PaymentGateway` interface
(`start()`), a `CapturableGateway` interface (`capture()`) implemented
only by PayPal/CreditCard, plus `TestGateway` for `is_test_account`
customers (no network calls, auto-completes).
`Controllers\Storefront\CheckoutController::capture()` (`GET
/checkout/capture`) is the PayPal/Stripe return-URL handler — it only
ever captures using the transaction_id/session_id already stored on that
order's `payments` row (never a client-supplied query value) and verifies
the captured amount matches the order total before marking anything
paid; don't reintroduce trusting the URL directly, see
`docs/SECURITY_AUDIT.md` finding #2 for why.

**Cart** (`Models\Cart`, instance-based, held in the `Container`;
`includes/Cart.php` is the kept-as-is legacy class it wraps/delegates
to): session-based (`$_SESSION['cart']`), rehydrated from the DB on every
read so prices/stock are always current. Stock for a product with
options is tracked per exact combination (`product_variants`/
`product_variant_values`), not per single option value. When resolving an
option value's price modifier, the query **must** scope by
`product_option_id`'s `product_id` — an unscoped lookup lets a crafted
option-value ID from a *different* product apply its price modifier here
(was CVE-shaped, see `docs/SECURITY_AUDIT.md` finding #1).

**Test accounts** (`is_test_account` on `customers`): orders placed while
logged in as one use `TestGateway`, are logged to `inventory_log` but
never decrement real stock, and are tagged `is_test_order = 1` —
excluded from every financial figure in `Controllers\Admin\FinanceAdminController`
and the dashboard. Created via Admin → Customers → Create Test User.

**Settings** live in the `settings` key/value table, read via
`Services\SettingsRepository::get($key, $default)` (a single shared
instance, cache invalidated on write — this fixes the old function-local-static
`getSetting()`'s same-request staleness gotcha *by construction*; the
standalone `getSetting()` function kept in `includes/functions.php` for
the legacy classes still has the old per-request-cache behavior, since
those classes only ever read settings, never write and immediately
re-read them in the same request). Not every setting has a seeded row in
`sql/schema.sql` (e.g. `enabled_languages`, `site_theme_package`,
`company_legal_name`/`vat_id`/`company_registration_number`) — those use
`SettingsRepository::upsert()` rather than `::update()` when saving;
check `Controllers\Admin\SettingsAdminController` for which pattern an
existing setting uses before copying it.

**New legal/compliance domain** (v2.00): `Models\CustomerRequest`
(abstract) is the shared base for `WithdrawalRequest` (order-level, fixed
window from `Models\WithdrawalRequest::calculateDeadline()`, hygiene
items excluded per-item via `withdrawal_request_items`) and `RmaTicket`
(item-level, warranty-length-based eligibility via
`RmaTicket::isEligible()`, up to 5 photo attachments) — common
`approve()`/`reject()`/`transitionTo()` behavior, subtype-specific fields
and eligibility rules. Both are customer-submitted (storefront
`WithdrawalController`/`RmaController`) and admin-reviewed
(`Controllers\Admin\WithdrawalAdminController`/`RmaAdminController`).
`Models\ContactMessage` backs the contact form (rate-limited via a
second `Services\RateLimiter` instance bound to `contact_message_attempts`,
same class/table pattern as login throttling). `Models\LegalDocument`
(Admin → Legal Documents) is either an uploaded PDF (extension +
content-sniffed, same posture as product image uploads — see
`docs/SECURITY_AUDIT.md` finding #6) or one generated from typed text via
`Services\PdfDocumentGenerator` (a thin wrapper around the existing
`SimplePdf` writer — no new dependency), downloadable at `/legal/{type}`.

## Security posture

CSRF (`Core\Csrf` for the OOP stack; the parallel `csrfField()`/
`requireCsrf()`/`verifyCsrf()` functions in `includes/functions.php` for
`install.php` and the kept legacy classes — both read/write the same
`$_SESSION['csrf_token']` key), prepared statements throughout, bcrypt
password hashing, session-fixation defenses on login (`Session::regenerate()`
+ `Csrf::rotate()`), and `.htaccess` hardening (CSP + security headers,
blocked direct access to `config/`/`includes/`/`sql/`/`src/`, blocked PHP
execution under `uploads/`, front-controller rewrite) are already in
place — see README.md's "Security" section for specifics.
`docs/SECURITY_AUDIT.md` has the full audit and what it fixed; treat that
file as required reading before touching cart pricing, payment capture,
order confirmation access control, or file uploads, since those are
exactly the areas that have already had real bugs found and fixed once —
and every one of those fixes was carried forward verbatim (with citation
comments) during the v2.00 rewrite, not just "generally" reproduced.

CMS page content (`Controllers\Storefront\PageController`) is rendered as
**trusted, unescaped HTML** by design (same model as WordPress page
content) — anyone who can edit Pages (Super Admin or Manager) can inject
markup/scripts into the storefront. This is a documented, intentional
trust boundary, not a bug to fix.
