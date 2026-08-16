# Changelog

All notable changes to shopRex are recorded here, newest first.

Versioning follows this project's own convention (see
[CONTRIBUTING.md](CONTRIBUTING.md#versioning), not semver): each release
bumps the version by exactly `0.01` (`1.00` → `1.01` → `1.02` → … → `1.10`
→ `1.11` → …), tracked in the [VERSION](VERSION) file and mirrored in the
`SHOPREX_VERSION` constant in `config/config.php`.

## [3.05] - 2026-08-16

New feature: admin-configurable sequential numbering.

### Added
- **Admin → Numbering**: lets an admin configure the format of the
  sequential numbers issued for customer accounts, invoices, RMA tickets,
  and withdrawal requests - starting value, increment, optional
  prefix/suffix, and an optional date component (raw PHP `date()` tokens,
  e.g. "Y" or "Ym") with an optional "reset the counter when the date
  component changes" toggle. Backed by a new `number_sequences` table and
  `Services\NumberSequenceService`, which allocates numbers atomically
  (`SELECT ... FOR UPDATE`) so concurrent registrations/orders/tickets can
  never collide - proven directly against MySQL with genuinely concurrent
  statements during verification, same methodology as the 3.02/3.03 race
  fixes.
- New `customers.customer_number`, `rma_tickets.rma_number`, and
  `withdrawal_requests.withdrawal_number` columns (nullable - existing
  rows are never backfilled, only new records get a number going forward).
  Shown in the relevant admin list/detail pages and the storefront RMA/
  withdrawal status pages.
- `Services\InvoiceGenerator::generateForOrder()`'s invoice numbers are
  now issued from this same mechanism instead of the old fixed
  `INV-{year}-{order id}` format, while preserving its existing
  "safe to call twice for the same order" guarantee (it now looks up an
  already-issued number for that order first, and only allocates a new
  one if none exists yet).
- **Order numbers are intentionally NOT included** - they keep their
  existing date+random scheme. A sequential/guessable order number would
  reintroduce the brute-forceable guest-order-lookup issue
  `docs/SECURITY_AUDIT.md` finding #4 already fixed.

## [3.04] - 2026-08-16

Manual verification pass for the 3.02/3.03 audit fixes on a local XAMPP
instance - no code changes, this entry records what was verified.

### Verified
- **Checkout / stock decrement**: placed a real order end-to-end
  (storefront cart -> checkout -> bank transfer); `orders`, `order_items`,
  `payments`, and `inventory_log` all wrote correctly and
  `products.stock_quantity` decremented exactly once, confirming the 3.02
  guarded-UPDATE change is a no-op in the normal (non-race) path.
- **Admin order-status save**: saved an order to paid via the admin UI -
  `orders`/`payments`/`transactions` all committed together; re-saving the
  same already-paid order left the ledger at exactly one row (no
  double-count).
- **Admin RMA-status save**: seeded a ticket and saved a status + resolution
  notes change together via the admin UI - both writes committed.
- **Order-confirmation / invoice-download access control** (finding #4):
  confirmed live - a guest's own just-placed order is viewable from the
  same session (200) and denied from an unrelated session (403 "not
  found"); invoice download correctly denies even the owning guest's own
  session (logged-in owner or admin only, by design) and allows admin
  access.
- **Both race fixes reproduced directly against MySQL**: ran genuinely
  concurrent SQL matching the guarded stock-decrement and
  `Order::markPaid()` statements - in each case exactly one of the two
  concurrent UPDATEs affected a row and the other matched zero, with the
  underlying value never going negative/double-counted - proving the fix
  at the database level, not just by code review.
- Re-verified payment-capture amount/identifier binding in both
  `PayPalGateway::capture()` and `CreditCardGateway::capture()`, the
  rate limiter's IP source (`REMOTE_ADDR`, not a spoofable header), and
  `InventoryAdminController::adjust()`'s existing transaction/ownership
  guards - no issues found.

## [3.03] - 2026-08-16

Second batch of fixes from the in-progress security/bug audit.

### Fixed
- **Payment-capture idempotency race** in `Order::markPaid()`: the
  double-ledger-entry guard (docs/SECURITY_AUDIT.md finding #3) was an
  in-memory check only, so two genuinely concurrent `/checkout/capture`
  requests for the same order (two tabs, a double-submitted return-URL hit
  while the gateway API call was still in flight) could both pass it
  before either had written, each inserting its own "sale" row into the
  `transactions` ledger. The `orders` UPDATE is now conditional
  (`AND payment_status != 'paid'`) with a `rowCount()` check, so whichever
  request loses the race cleanly no-ops instead of double-counting revenue.
- Same "sequential-only" transaction gap as `OrderAdminController::save()`
  (fixed in 3.02) also existed in `RmaAdminController::save()` (status
  transition + resolution notes as two unwrapped statements) - now
  transaction-wrapped the same way.

## [3.02] - 2026-08-16

First batch of fixes from a full security/bug audit (in progress).

### Fixed
- **Stock overselling race condition** in `CheckoutService::placeOrder()`:
  the pre-checkout stock check read `stock_quantity` before the order's DB
  transaction opened, then the actual decrement was an unconditional
  `UPDATE ... SET stock_quantity = stock_quantity - ?`. Two concurrent
  checkouts for the last unit of an item could both pass the check and both
  decrement, driving stock negative and overselling. The three
  stock-decrement statements (`products`, `product_variants`,
  `product_option_values`) are now conditional
  (`AND stock_quantity >= ?`) with a `rowCount()` check - a checkout that
  loses the race now cleanly fails with "just sold out" and rolls back,
  instead of succeeding on stock that was already gone.
- **Inconsistent state on a failed admin order-status save**:
  `OrderAdminController::save()` ran the `orders` UPDATE, the `payments`
  UPDATE, and the `transactions` ledger INSERT as separate, unwrapped
  statements - a failure partway through (e.g. the ledger insert) could
  leave an order marked paid/refunded with no matching ledger entry. Now
  wrapped in one transaction with rollback + an admin-facing error flash on
  failure.
- Stale doc comment in `.htaccess` referencing the now-deleted
  `includes/functions.php` for the CSRF check - updated to reference
  `Core\Csrf`.

## [3.01] - 2026-08-15

Fixes the remaining items from the codebase-wide comment sweep's flagged
findings.

### Fixed
- `ProductAdminController::delete()` deleted a product's DB row without
  cleaning up its uploaded image files - every dependent DB row already
  gets cleaned up automatically by `ON DELETE CASCADE` foreign keys, but
  the actual files on disk never do. Now unlinks every attached
  image/cropped-image file first, same as `ProductImageController`'s own
  single-image delete action already did.
- `CartController::update()` called `Cart::getItems()` (which re-hydrates
  the entire cart from the database) once per posted quantity field
  inside its loop, instead of once total - an O(n²) query pattern for a
  cart with n distinct lines. Hoisted the one needed lookup (product id
  per cart-line key) above the loop.
- `RmaController::submit()` and `WithdrawalController::submit()` both
  created their DB record first, then ran photo-upload/notification-email
  steps that could still throw (this app's PDO connection is configured
  to raise an exception on any SQL error) - a failure there would have
  surfaced as an uncaught 500 even though the ticket/request had already
  been saved, leaving the customer with no confirmation it went through.
  Both now wrap those best-effort steps in a try/catch that logs any
  failure but still shows the normal success confirmation, since the
  record itself is the part that actually matters.
- `ImageCropController` only ever persisted the crop *selection*
  rectangle's size (`crop_width`/`crop_height`), never the *output* size
  it was resized to - so reopening the crop tool on an already-cropped
  image pre-filled the "Output Width"/"Output Height" fields with the
  previous selection's pixel size instead of the previous output size.
  Added two new columns (`crop_target_width`/`crop_target_height`) to
  `product_images` to record that separately.

### Investigated, not a bug
- `AuthController::forgotPassword()`'s rate limiter only records a new
  failed attempt when the request *isn't* already throttled, which looks
  at first glance like a throttled request "does nothing" toward
  extending the lockout. Checked against `login()`'s identical pattern in
  the same file and `Services\RateLimiter`'s sliding-window design (age
  out attempts older than the window, no escalating penalty) - this is
  the intentional, consistent behavior everywhere the class is used, not
  a gap specific to the password-reset form.

## [3.00] - 2026-08-15

A sanctioned exception to the `+0.01` versioning convention (like v2.00
before it) - see [CONTRIBUTING.md](CONTRIBUTING.md#versioning). Removes
every remaining `includes/` class, closing out the "deferred polish" gap
v2.00's CLAUDE.md explicitly called out at the time.

### Removed
- **The `includes/` directory no longer contains any PHP class** - only
  `includes/lang/*.php` (language-string data) is left. Every class that
  used to live there and was still `require_once`'d as-is has been ported
  into the `ShopRex\` namespace under `src/Services/` and is now reached
  through the ordinary autoloader:
  - `includes/Mailer.php` → `Services\Mailer`
  - `includes/InvoiceGenerator.php` → `Services\InvoiceGenerator`
  - `includes/SimplePdf.php` → `Services\SimplePdf`
  - `includes/ImageProcessor.php` → `Services\ImageProcessor`
  - `includes/Cart.php` → deleted outright rather than ported -
    `Models\Cart` (built during the original v2.00 rewrite) already fully
    replaced it; the one remaining live caller of the old static class
    (`Cart::count()` in the storefront header's cart badge) was switched
    to a new `getCartItemCount()` view-helper shim.
  - `includes/GdprTools.php`/`GdprCleanup.php` → deleted outright -
    `Services\GdprService` (also already built during v2.00) already
    independently reimplemented everything in both files; the one
    remaining caller (`admin/cron/gdpr_cleanup.php`) now boots the real
    app container and calls `GdprService::runInactivityCleanup()`
    directly instead of requiring the old files.
  - `includes/functions.php`/`i18n.php` → deleted - every function they
    still exported either already had a `src/view-helpers.php` shim
    (used at runtime by the ported classes above, since none of them
    ever actually required `functions.php` themselves) or was needed
    only by `install.php`, which now carries its own small,
    self-contained copies of `e()`/the CSRF helpers/`redirect()`/
    `writeInstalledConfigFile()` - it still can't use the rest of the
    app's classes, since its job is creating the database/config those
    depend on in the first place.
- Every call site updated accordingly (`CheckoutService`, `GdprService`,
  `PdfDocumentGenerator`, and every controller that sends an email or
  crops an image), and every stale `includes/*.php` reference left behind
  in `CLAUDE.md`, `README.md`, `README.de.md`, and assorted code
  docblocks/comments corrected to point at the new locations.

### Changed
- **No upgrade path from any version before 3.00** - this cutover removed
  the last concrete backward-compatibility affordance the app had (the
  three `try/catch` blocks from v2.09). There is no migrations system;
  `sql/schema.sql` is the current-version-only schema. A pre-3.00 site
  must be treated as a fresh install. Starting from 3.00, ordinary
  `+0.01` point releases go back to being the expected, supported
  upgrade path (as they already were between 2.01 and 2.09) - see
  [CONTRIBUTING.md](CONTRIBUTING.md#versioning) for the full policy.

### Verified
- `php -l` on all 34 touched/added PHP files.
- A live, isolated fresh-install run through `install.php`'s full 3-step
  wizard (requirements check → database setup → admin account creation),
  confirming its now-self-contained CSRF/`e()`/`writeInstalledConfigFile()`
  copies work exactly as before.
- A real end-to-end checkout (add to cart → place a bank-transfer order)
  through the live HTTP front controller, confirming `Services\InvoiceGenerator`
  generated and saved a PDF and `Services\Mailer` rendered, logged, and
  attempted to send the order confirmation email - both via the real
  `Services\CheckoutService` call path, not a simulation.
- The storefront cart badge (`getCartItemCount()`) reflecting a real
  added item.
- `admin/cron/gdpr_cleanup.php` running successfully end-to-end through
  the real app container.
- A full storefront + admin route sweep and an Apache error-log check,
  both clean.

## [2.09] - 2026-08-15

### Removed
- `includes/PaymentGateway.php` - confirmed dead code, superseded by
  `src/Payment/*` since the v2.00 rewrite and referenced nowhere else
  (`src/container.php` never loaded it). Updated the remaining stale
  references to it in `CLAUDE.md`, `includes/functions.php`,
  `README.md`, and `README.de.md` to point at `src/Payment/` instead
  (that "Payments"/"Test users" README section had been stale since the
  v2.00 cutover, still describing the pre-rewrite gateway file and the
  removed `checkout_process.php` route).
- Three `try { ... } catch (\Throwable $e) { /* invoices table not
  present yet */ }` blocks (`AccountController::index()`,
  `OrderController::confirmation()`, `Mailer::sendOrderConfirmation()`) -
  defensive tolerance for running the app against a database that
  predates the `invoices` table, which has no other support anywhere in
  the app (no migrations system, no version-upgrade wizard - `sql/schema.sql`
  is the only schema source and always includes it). All three are now
  plain, unconditional queries.
- `TaxRateAdminController`'s injected `TaxCalculator` dependency, which
  was never actually used anywhere in the class.

## [2.08] - 2026-08-15

### Fixed
- `includes/InvoiceGenerator.php` only had `en`/`de` invoice label sets -
  a French order's generated PDF invoice was entirely in English. Added
  a full `fr` label set.
- `includes/Mailer.php`'s order-confirmation email had several hardcoded
  English strings that never went through `__()`: the order-items table's
  column headers ("Item"/"Qty"/"Price") and totals rows ("Subtotal"/
  "Shipping"/"Tax"/"Total"), plus the bank-transfer and pay-by-invoice
  payment instructions block. Both now reuse the exact same translation
  keys as the storefront order confirmation page, so the on-screen page
  and the emailed confirmation say the same thing in the same language.
  Verified via a standalone script that calls both functions directly
  with a French order and checks every label made it into the generated
  PDF/HTML - German re-checked too, to confirm it was unaffected.

## [2.07] - 2026-08-15

### Added
- Thorough, plain-language comments and docblocks across the entire
  codebase (157 files: `src/Core`, `src/Support`, `src/Models`,
  `src/Services`, `src/Payment`, every `Controllers\Storefront`/
  `Controllers\Admin` class, every view under `src/Views/`, `src/bootstrap.php`/
  `container.php`/`view-helpers.php`/`routes/*.php`, the kept legacy
  `includes/*.php` classes, and the three entry points) - every class,
  method, and non-trivial line now explains what it does and why, aimed
  at a newcomer being able to safely extend the app. Comments-only: no
  logic, behavior, or output changed anywhere (verified per-file via
  `php -l` and a full-diff review confirming every removed line was
  itself a comment being expanded).

### Fixed
- Two dead-code findings surfaced while adding these comments were left
  as-is (out of scope for a comments-only pass) but are worth a look:
  `includes/PaymentGateway.php` is unreferenced anywhere (superseded by
  `src/Payment/*`), and `TaxRateAdminController`'s injected
  `TaxCalculator` is never used. Also flagged, not yet fixed: missing
  rate-limit lockout extension in `AuthController::forgotPassword()`,
  an O(n²)-ish re-hydration pattern in `CartController::update()`,
  `RmaController::submit()` creating a ticket before its photo-upload/
  email step, `ProductAdminController::delete()` not guarding against a
  still-referenced product, `includes/InvoiceGenerator.php` having no
  French label set (French orders get an English invoice), hardcoded
  English strings in `includes/Mailer.php`'s order-confirmation email
  (item table + bank-transfer/invoice instructions), and
  `ImageCropController` not persisting the crop tool's chosen output
  dimensions (only the selection rectangle).

## [2.06] - 2026-08-15

### Fixed
- `src/Views/storefront/page/not_found.php` and `category/not_found.php`
  showed a hardcoded English "could not be found" message regardless of
  the storefront's active language, unlike their `product/`/`order/`
  siblings which already went through `__()`. Added `page.not_found_text`/
  `category.not_found_text` keys (en/de/fr) and switched both views to
  use them. Found during a documentation pass over the storefront views.

## [2.05] - 2026-08-15

### Added
- `sql/seed_demo.sql`'s three demo products (and their Size/Color options)
  now carry German and French translations - previously the optional demo
  data had none at all, so installing with it and switching the storefront
  to DE/FR silently fell back to the English text everywhere (working as
  designed via `Services\TranslationOverlay`, but making the trilingual
  feature look untested rather than just untranslated on a fresh demo
  install). Size letters (S/M/L) are left untranslated on purpose - they're
  the same abbreviation in all three languages, so that's the fallback
  behavior in action, not a gap. Category names stay English-only by
  design either way (see `category_translations`' schema comment).

## [2.04] - 2026-08-15

### Added
- The `/legal/{type}` download route (Admin -> Legal Documents' uploaded-
  or-generated PDFs) is now actually linked from the storefront: the
  footer's "Links" column lists every document type currently on offer,
  each pointing at its own `/legal/{type}`. Since `type` is admin-defined
  free text (not a fixed set), the list is built dynamically
  (`Models\LegalDocument::allForLanguage()`) rather than hardcoded -
  same current-language -> shop-default-language -> any fallback as a
  single document's own lookup. Previously the route existed and worked
  but nothing in the UI ever pointed at it.

## [2.03] - 2026-08-15

### Fixed
- A full sweep for links the v2.00 cutover missed (beyond `install.php`'s,
  fixed in 2.02):
  - The seeded "Home" and "Contact" menu items (`sql/schema.sql`, and the
    already-installed database) pointed at `index.php`/`contact.php`
    instead of `/`/`contact` - every fresh install's default nav "Home"
    link 404'd.
  - `PayPalGateway`/`CreditCardGateway`'s `cancel_url` still pointed at
    `/checkout.php?cancelled=1` - cancelling a PayPal or Stripe payment
    and being sent back to the shop hit a 404 instead of the cart.
  - Three admin-facing help strings (all three languages) still described
    the old URL shapes: the Pages slug hint (`/page.php?slug=...` instead
    of `/page/...`), the "test user created" message (`/login.php`
    instead of `/login`), and the custom menu-link URL field's example
    (`index.php`, which - per `MenuTreeService::resolveUrl()` - actually
    resolves to `/index.php` and 404s; now suggests a blank value for the
    homepage instead).
  - `includes/PaymentGateway.php` (the pre-rewrite payment class) still
    has the same stale paths internally, but is confirmed dead code -
    nothing `require`s it since `src/Payment/*` took over - so it was left
    alone rather than edited for its own sake.

## [2.02] - 2026-08-15

### Fixed
- `install.php`'s "already installed" and "setup complete" screens both
  linked to `admin/login.php` and `index.php` - the exact two `.php`-
  suffixed paths the v2.00 cutover removed. `install.php` wasn't rewritten
  as part of that cutover (it's a standalone script that has to run
  before the rest of the app's dependencies exist), so these two
  hardcoded links were missed. Now point at `admin/login` and `./`.

## [2.01] - 2026-08-15

### Fixed
- `detectSiteUrl()` (`config/config.php`, prefills the installer's Site
  URL field) dropped the subdirectory whenever the project root itself
  was reached through a symlinked/junctioned web root - a common local
  setup (e.g. XAMPP's `htdocs/shopRex` pointed at the real project
  directory via a symlink), since it compared `DOCUMENT_ROOT` against a
  PHP-resolved (symlink-following) project path that no longer shared a
  prefix with it. Found by running a genuine fresh install through the
  wizard for the first time since the v2.00 rewrite. Now derived from
  `$_SERVER['SCRIPT_NAME']` instead, which stays correct regardless of
  symlinks.

## [2.00] - 2026-08-14

A full architectural rewrite from procedural PHP to an OOP structure, plus
a new legal/compliance domain (RMA tickets, right of withdrawal, contact
form, legal documents). An explicit, one-time exception to the `+0.01`
versioning convention below - see
[CONTRIBUTING.md](CONTRIBUTING.md#versioning).

### Changed - architecture
- Every top-level `.php` page is gone. `index.php`/`admin/index.php` are
  now thin front controllers dispatching through a hand-rolled `Router`
  (`src/routes/web.php`/`admin.php`) - adding a page is a route
  registration, not a new file. Zero Composer/npm dependencies, same as
  before.
- `includes/functions.php`'s 62 procedural functions are ported into real
  classes under `src/`: `Models\*` (Product, Category, Cart, Order,
  Customer, ShippingMethod, TaxRate, MenuItem, Page, ...),
  `Services\*` (CategoryTreeService, MenuTreeService, TaxCalculator,
  DiscountCalculator, ShippingCalculator, TranslationOverlay, CheckoutService,
  InvoiceService, Mailer, GdprService, RateLimiter, SettingsRepository,
  I18n, PdfDocumentGenerator, ...), `Payment\*Gateway` (PayPal/CreditCard/
  BankTransfer/Invoice/Test, one interface, `capture()` on the two that
  support it), and a matching `Controllers\Storefront\*`/`Controllers\Admin\*`
  pair per page. `includes/functions.php` itself is pruned to the handful
  of functions still needed by `install.php` and the few small,
  already-proper classes kept as-is (`Cart.php`, `Mailer.php`,
  `InvoiceGenerator.php`, `SimplePdf.php`, `ImageProcessor.php`,
  `GdprTools.php`, `GdprCleanup.php`, `PaymentGateway.php`).
- Concrete use of inheritance: `Models\CustomerRequest` (abstract) is the
  shared base for the new `WithdrawalRequest` and `RmaTicket` models -
  common status-transition/approve/reject behavior, subtype-specific
  fields and eligibility rules.
- Storefront and admin theming go through a dedicated `Core\Renderer` +
  `Core\ThemeManager`: a theme *package* (`themes/<key>/theme.json` +
  `style.css`, still web-servable) can override any of
  `header.php`/`footer.php`/`home.php` under
  `src/Views/storefront/theme/<key>/`, falling back to the `default`
  package for whatever it doesn't provide - the same mechanism the
  built-in "Sidebar Filters" package already used, now fully inside the
  OOP view layer instead of a handful of untouched procedural files.
- Clean URLs throughout (`/product/{slug}`, `/category/{slug}`,
  `/admin/orders/{id}`, ...) - no more `?slug=`/`?category=<id>`-style
  query strings or `.php`-suffixed paths anywhere in the app, including
  every internal link generator (nav menus, breadcrumbs, emails, PDFs).
- `.htaccess` gained a rewrite block for the two front controllers, and a
  new `src/.htaccess` blocking all direct access to the `src/` tree
  (matching the existing `config/`/`includes/`/`sql/` posture) - closes an
  info-disclosure gap where an uncaught error hit directly could leak a
  full filesystem path.

### Added
- Contact form (rate-limited, honeypot field), with an admin inbox
  (Admin → Contact Messages).
- Functional, self-service right of withdrawal: a customer can open an
  eligible order and submit a withdrawal request (which items, an
  optional reason); admin reviews/approves/rejects it (Admin →
  Withdrawals) with an optional notification email. Hygiene-flagged items
  are excluded from what can be withdrawn, enforced server-side.
- RMA / defect tickets: a customer can report a problem with a specific
  order item, choosing statutory or manufacturer warranty, with up to 5
  photo attachments; eligibility is computed from the product's
  configured warranty length and the order date, re-verified server-side
  on submission. Admin reviews/resolves tickets (Admin → RMA Tickets)
  with resolution notes and an optional notification email.
- Per-product warranty/battery/hygiene fields (Admin → Products → edit):
  statutory and manufacturer warranty length in months, manufacturer
  warranty notes, "contains a battery" and "hygiene product" flags -
  surfaced on the product page and factored into RMA eligibility and
  withdrawal exclusion.
- Legal documents (Admin → Legal Documents): a document per (type,
  language) either uploaded as a PDF (extension + content-sniffed, same
  posture as product image uploads) or generated on the fly from typed
  text via the existing dependency-free PDF writer - downloadable at
  `/legal/{type}`.
- Company/legal invoice fields (Admin → Settings): legal company name,
  VAT ID, registration number, printed on generated invoices when filled
  in.
- `admin/login`/`admin/logout` are now real routes backed by
  `Controllers\Admin\AdminAuthController` (previously only reachable via
  the standalone procedural `admin/login.php`).

### Fixed
- Admin sidebar nav "active" highlighting, which never worked correctly
  for a detail/edit page (e.g. `/admin/orders/5`) even before this
  rewrite - now correctly highlights the closest-matching section on any
  route depth.
- Admin → Settings' layout/theme-package picker could never actually list
  a non-default theme package, because it read from the wrong
  `ThemeManager` instance - it silently only ever showed "Default".

## [1.01] - 2026-08-14

### Added
- [CLAUDE.md](CLAUDE.md) - guidance for Claude Code (and similar coding
  agents) working in this repository: common commands, the versioning
  convention, and the cross-file architecture notes (bootstrap/page model,
  `getSetting()` caching gotcha, theme resolution, admin RBAC, the i18n
  and product/option translation systems, payment gateway capture
  security, cart stock-scoping security, and settings save patterns).

## [1.00] - 2026-08-14

Initial tagged release - the project in its state at the point versioning
started, covering everything built up to here:

### Added
- Full storefront + back office: product catalog with unlimited category
  nesting, options/variants with per-combination stock, session cart,
  checkout with PayPal/Stripe/bank transfer, PDF invoices, editable CMS
  pages and email templates, GDPR self-service export/erasure, admin
  roles (Super Admin/Manager), test accounts for trial orders, and an
  installer-driven setup - see [README.md](README.md)'s Features list for
  the full rundown.
- Trilingual out of the box (English/German/French) across both the
  storefront and back office, with per-language enable/disable from
  Admin → Settings → Languages (down to a single language, which removes
  language-switching UI entirely) and per-language translation of product
  name/short description/description/option labels from Admin → Products
  → edit.
- CMS page seed content (Legal Notice, Privacy Policy, About Us,
  Copyright) in all three languages.
- [README.de.md](README.de.md) - a German translation of the project
  README.

### Security
- CSRF protection, session hardening (`HttpOnly`/`SameSite=Lax`/`Secure`
  cookies, session-fixation defenses), prepared statements throughout, and
  `.htaccess` hardening (security headers, a CSP, blocked direct access to
  `config/`/`includes/`/`sql/`, blocked PHP execution under `uploads/`).
- See [docs/SECURITY_AUDIT.md](docs/SECURITY_AUDIT.md) for the full audit
  and the fixes it produced: a cart price-manipulation bug, payment
  capture not being bound to its order (and not being idempotent), an
  unauthenticated order-confirmation IDOR, missing login rate limiting,
  and image uploads trusted by extension alone rather than content.
