# Changelog

All notable changes to shopRex are recorded here, newest first.

Versioning follows this project's own convention (see
[CONTRIBUTING.md](CONTRIBUTING.md#versioning), not semver): each release
bumps the version by exactly `0.01` (`1.00` → `1.01` → `1.02` → … → `1.10`
→ `1.11` → …), tracked in the [VERSION](VERSION) file and mirrored in the
`SHOPREX_VERSION` constant in `config/config.php`.

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
