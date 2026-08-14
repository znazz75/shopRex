# Changelog

All notable changes to shopRex are recorded here, newest first.

Versioning follows this project's own convention (see
[CONTRIBUTING.md](CONTRIBUTING.md#versioning), not semver): each release
bumps the version by exactly `0.01` (`1.00` → `1.01` → `1.02` → … → `1.10`
→ `1.11` → …), tracked in the [VERSION](VERSION) file and mirrored in the
`SHOPREX_VERSION` constant in `config/config.php`.

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
