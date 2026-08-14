# shopRex

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
![PHP](https://img.shields.io/badge/PHP-8.0%2B-777bb4)
![MySQL](https://img.shields.io/badge/MySQL-5.7%2B%20%2F%20MariaDB%2010.3%2B-4479a1)
![No build step](https://img.shields.io/badge/dependencies-zero%20required-brightgreen)

A basic PHP + MySQL online shop framework — plain PHP (PDO, no Composer
dependencies required to run), procedural/light-OOP, meant as a starting
point you extend rather than a finished product. The storefront runs on
Bootstrap 5 (+ jQuery/jQuery UI for a couple of interactive admin tools,
Quill.js for rich text, Cropper.js for image cropping — all loaded from
public CDNs, see [External libraries](#external-libraries)). The whole
site (storefront and back office) is bilingual out of the box (English/
German) and built to take more languages, VAT is fully configurable, and
checkout generates a real PDF invoice — see the feature list below.

## Features

**Storefront**
- Modern **Bootstrap 5** layout, **switchable from the back office** (Admin → Settings → 3 built-in themes: Default, Midnight/Dark, Ocean) — see [Frontend theme](#frontend-theme)
- **Bilingual (English/German), extensible to any language** — a language switcher is always available in the header; see [Languages](#languages)
- Product listing with sorting (newest, price, name), search, and **configurable pagination** (20/50/200/all, persisted per visitor) — see [Pagination](#pagination)
- Categories with **unlimited nesting** (category > subcategory > subcategory > ...), with breadcrumbs and a hover-dropdown nav
- **Site search** across both products and categories ([search.php](search.php))
- Product detail page with option selection (e.g. Size, Color), stock-aware "Add to cart", a **multi-image gallery** (Bootstrap carousel + thumbnails) with a per-image caption, and **time-limited discount badges** with the date range shown — see [Discounts & availability windows](#discounts--availability-windows)
- **Configurable VAT** — gross (tax-included) prices shown everywhere on the storefront; the cart/checkout break out the net price and tax separately — see [VAT](#vat)
- Session-based shopping cart (add/update/remove, live stock checks) with a **"Continue Shopping" button** that returns to the last product you viewed
- Checkout with **PayPal**, **credit card** (Stripe Checkout), or **online bank transfer**
- A **PDF invoice** is generated at checkout, in the customer's language, with a VAT breakdown, emailed as an attachment, and stored in their account — see [Invoices](#invoices)
- Order confirmation page + editable HTML emails for registration, password reset, and every order event — see [Email templates](#email-templates)
- Customer accounts (register/login/order history) with **self-service data export and account deletion** — see [Data protection (GDPR)](#data-protection-gdpr)
- **Editable CMS pages** — Legal Notice, Privacy Policy, About Us, Copyright ship as editable seed content, plus you can add your own ([page.php](page.php))
- **Editable main menu and footer submenu**, both nestable, both managed from the back office ([Menus](#menus))

**Back office** (`/admin`)
- **Installer-driven setup** — no manual SQL import required, see below
- **Multiple admin accounts with roles** — Super Admin (full access) and Manager (product/category/inventory/content only); see [Admin roles](#admin-roles)
- **Test user accounts** for trial runs — orders placed under a test account use a simulated payment, never touch real stock counts, and are excluded from every financial report; see [Test users](#test-users)
- Product management (CRUD, options/variants, **per-item discount + availability scheduling**, **net/gross price entry with live conversion**) plus a dedicated **image manager**: upload multiple images per product, caption each one, drag to reorder, pick the primary image, and **crop with Cropper.js** (set the crop box and output width/height; a cropped derivative is generated server-side with GD) — see [admin/product_images.php](admin/product_images.php) and [admin/image_crop.php](admin/image_crop.php)
- Category management (unlimited nesting, indented parent picker, cycle prevention)
- Inventory management (stock levels, manual adjustments, movement log clearly flagging test-order entries, low-stock alerts)
- **Tax Rates** — multiple VAT rates with one marked default; toggle VAT on/off entirely — see [VAT](#vat)
- **Pages** — rich-text (Quill.js) editor for the CMS pages above
- **Menus** — drag-to-reorder (jQuery UI Sortable) management of the main nav and footer submenu, with custom-URL/category/page link types and nesting for dropdowns
- **Email Templates** — edit the shared header/footer and every email's subject/body, per language, with a token reference — see [Email templates](#email-templates)
- **Settings** — shop details, bank transfer details, default items-per-page, default language, VAT toggle, data-retention period, and the frontend theme switcher
- Financial management (revenue dashboard, revenue by month/payment method, transaction ledger — real orders only) — Super Admin only
- Customer management (list, order history, block/unblock, create test users, **GDPR data export/erasure**) — Super Admin only
- Order management (status + payment status updates, customer notifications, invoice download, test-order badges/filter) — Super Admin only

## Requirements

- PHP 8.0+ with the `pdo_mysql`, `curl`, `gd` (image cropping), `iconv` and `mbstring` (PDF invoice text encoding) extensions - all bundled/enabled by default on most PHP installs
- MySQL 5.7+ / MariaDB 10.3+
- A local mail transport for `mail()` to actually deliver email (or swap in SMTP — see below)
- Internet access from the visitor's browser to load Bootstrap/jQuery/Quill/Cropper.js from their CDNs (see [External libraries](#external-libraries) for self-hosting them instead)
- Apache with `mod_authz_core`/`mod_headers` for the `.htaccess` files this project ships with to take effect (see [Security](#security)) - on nginx, port their rules into your server block instead, since nginx doesn't read `.htaccess`

## Setup

1. **Run it locally** with PHP's built-in server from the project root:
   ```bash
   php -S localhost:8000
   ```
   For real hosting, point your web server's document root at this folder
   **or at a subdirectory of it** (e.g. `http://example.com/shopRex/`) - both
   work with no code changes, see [Running in a subdirectory](#running-in-a-subdirectory).
   Make sure `config/`, `includes/`, and `sql/` are **not** web-accessible
   (or add a `.htaccess`/nginx rule blocking them) — everything the browser
   needs lives in the project root, `assets/`, `admin/`, and `uploads/`.

2. **Open the site in a browser.** Any page (storefront or admin) redirects
   to the installer, [install.php](install.php), on first run:
   1. **Requirements check** — PHP version, `pdo_mysql`/`curl` extensions, and that `config/` and `uploads/products/` are writable.
   2. **Site & database setup** — the **Site URL** field is prefilled by auto-detecting scheme+host+subdirectory from this very request (correct it if it's wrong, e.g. behind a reverse proxy - see [Running in a subdirectory](#running-in-a-subdirectory)); enter your database host, port, name, user, and password. The database is created if it doesn't already exist and the schema is imported automatically (with an optional demo-content checkbox). This writes `config/installed.php` — **not committed to git**, since it holds your DB password.
   3. **Administrator account** — enter the username, email, and password for the first admin. This account is created with the **Super Admin** role.

   Once an admin account exists, `install.php` permanently refuses to run
   again (even if visited directly) so it can't be used to wipe a live shop.
   If you ever need to reconfigure the database connection by hand, edit
   `config/installed.php` directly (or change the Site URL from **Admin →
   Settings** without touching the database connection).

   Storefront: http://localhost:8000/index.php
   Admin: http://localhost:8000/admin/login.php

   *(Advanced/unattended setups: you can skip the installer entirely by
   running `mysql -u root -p your_db < sql/schema.sql` yourself and setting
   the `SHOPREX_DB_*`/`SHOPREX_SITE_URL` environment variables — see
   `config/config.php`. You'll still need to insert your own `admin_users`
   row in that case.)*

## Running in a subdirectory

Every internal link, redirect, asset URL, and email the app builds uses the
`SITE_URL` constant (`rtrim(SITE_URL, '/') . '/index.php'`, etc.) - there is
no assumption anywhere that the site lives at your domain's root. `SITE_URL`
itself comes from, in order: `config/installed.php` (set once by the
installer, editable later from **Admin → Settings → Site URL**), the
`SHOPREX_SITE_URL` environment variable, or - before either of those exists
- an automatic guess (`detectSiteUrl()` in `config/config.php`) that
compares this project's real filesystem path against the web server's
document root, so it correctly includes a subdirectory like
`http://localhost/shopRex` without any configuration. If that guess is ever
wrong (unusual server setups, some reverse proxies), just correct it in the
installer or in **Admin → Settings** - nothing else needs to change.
Using `https://` as the Site URL's scheme also makes every plain-HTTP
request auto-redirect to HTTPS (see [Security](#security)).

## Security

- **CSRF**: every state-changing form/AJAX call is protected by a
  per-session token (`csrfField()`/`requireCsrf()` in
  `includes/functions.php`). `verifyCsrf()` requires *both* the submitted
  and the session token to be non-empty before comparing - `hash_equals('',
  '')` returns `true` in PHP, so without that check a forged request with
  no token field could pass whenever the victim's session hadn't generated
  one yet. The session cookie itself is `HttpOnly`, `SameSite=Lax`, and
  `Secure` over HTTPS (`config/config.php`), and login/registration
  regenerate the session ID (`regenerateSession()`) to prevent fixation.
- **`.htaccess` hardening** (Apache only, see [Requirements](#requirements)):
  the project root sets `X-Frame-Options`, `X-Content-Type-Options`,
  `Referrer-Policy`, and a `Permissions-Policy` header, and blocks dotfiles;
  `config/`, `includes/`, `sql/`, and `admin/cron/` are fully denied from
  direct web access; `uploads/` blocks PHP execution (defense in depth
  against a malicious file upload); `uploads/invoices/` is fully denied
  (invoices are only ever served through the auth-checked
  `invoice_download.php`).
- **Force HTTPS**: whenever your configured Site URL (**Admin → Settings**)
  uses `https://`, every plain-HTTP request is 301-redirected to its HTTPS
  equivalent (`config/config.php`, aware of `X-Forwarded-Proto` behind a
  reverse proxy). Tied to the Site URL's own scheme rather than always-on,
  so a `http://` Site URL - local development, a not-yet-HTTPS staging site
  - is never forced and never redirect-loops. An equivalent, opt-in
  server-level version (commented out by default) is in the root
  `.htaccess` for anyone who'd rather Apache handle it before PHP runs.
- Passwords are hashed with `password_hash()`/`password_verify()`
  (bcrypt); prepared statements are used throughout.

This has not had a full professional security audit - review before
production use, especially file upload handling and admin access control.

## Admin roles

Defined in [admin/includes/roles.php](admin/includes/roles.php):

| Role | Access |
|---|---|
| **Super Admin** | Everything: products, categories, inventory, pages, menus, orders, finance, customers, settings, shipping, and managing other admin accounts |
| **Manager** | Products, categories, inventory, pages, and menus only ("article/content management") — no access to orders, finance, customers, settings, shipping, or admin accounts |

Manage admin accounts under **Admin → Admin Accounts** (Super Admin only,
[admin/admins.php](admin/admins.php)): create additional accounts, assign a
role, disable/re-enable, reset passwords, or delete. The system always
keeps at least one active Super Admin — you can't delete, demote, or
disable the last one.

To add a new role, add a label to `ADMIN_ROLES` and list which sections it
grants access to in `ADMIN_CAPABILITIES` in that same file; update the
`role` column's `ENUM` in `sql/schema.sql` (or `ALTER TABLE admin_users
MODIFY role ENUM(...)` on an existing database) to match.

## Frontend theme

**Admin → Settings** has two independent controls, both applying to every
visitor immediately:

### Layout (theme packages)

A layout is a whole alternate page structure, not just colors - e.g. the
built-in **Sidebar Filters** package moves the category tree into a
persistent left sidebar instead of the default top breadcrumb/chip row.
Packages live in `themes/<key>/` and are auto-discovered the same way
`includes/lang/*.php` languages are - **to add one**, create a new
`themes/<key>/theme.json` (`{"name": "...", "description": "..."}`) and it
appears in Admin → Settings → Layout automatically, no code changes needed.
A package can override any of three template slots by dropping in a file
with the matching name:

| File | Overrides | Falls back to |
|---|---|---|
| `header.php` | Everything from `<html>` through the opening of `<main>` | `includes/header.php` |
| `footer.php` | Everything from the close of `<main>` onward | `includes/footer.php` |
| `home.php` | The product-listing content on `index.php` (home, category, and in-category-search views) | `includes/home.php` |

A package only needs to provide the files it actually changes - the
built-in **Default** package provides none at all (proving the fallback is
transparent), and **Sidebar Filters** only overrides `home.php` plus its own
`style.css` (loaded after `assets/css/style.css`, so it can add layout CSS
or override further without touching the core stylesheet). The resolver is
`themeTemplatePath()` in `includes/functions.php`; every root-level
storefront page calls it instead of requiring `includes/header.php`/
`footer.php` directly. This intentionally does **not** include an in-browser
"upload a theme .zip" flow - that would let anyone with admin access upload
and run arbitrary PHP on the server; adding one later is a separate,
security-reviewed change.

### Color accent

Recolors buttons, links, badges, and the navbar within whichever Layout is
active. Three ship out of the box (`includes/functions.php`, `THEMES`
constant): **Default** (light), **Midnight** (dark, using Bootstrap 5.3's
native `data-bs-theme="dark"` color mode), and **Ocean** (light, teal
accent). Bootstrap is loaded from a CDN rather than built from Sass, so
component colors are compiled into fixed values instead of runtime CSS
variables — each theme sets a `--shop-accent` custom property that
`assets/css/style.css` uses to recolor the specific Bootstrap classes this
project actually renders (buttons, links, badges, form checks, etc.). To
add one: add an entry to `THEMES` with a `bs_theme` (`light`/`dark`), an
`accent` hex color, and a `navbar_bg` hex color — no CSS changes needed
unless you want to recolor something beyond what's already listed in
`style.css`.

## Languages

Ships bilingual (English + German) across **both** the storefront and the
back office, and is built to take more languages without code changes to
every page:

- `includes/lang/en.php` and `includes/lang/de.php` each return a flat
  `'namespace.key' => 'string'` array; `__('key', ['token' => $value])`
  (`includes/i18n.php`) looks up the current language, falls back to
  English for anything missing, and does `{token}` substitution.
- **Add a language** by dropping a new `includes/lang/xx.php` file with the
  same keys (a `_meta_name` entry sets its display name, e.g. `'Français'`)
  - it's auto-detected everywhere a language picker appears, no other
    changes needed. **Admin → Settings → Languages** shows this same
    how-to directly in the back office too.
- **Enable/disable individual languages** from **Admin → Settings →
  Languages** without deleting the underlying file - a language stays
  discovered (still usable for, e.g., formatting an existing order/customer
  that captured it before being disabled) but disappears from every
  switcher, `?lang=`, and the per-language tabs in Pages/Email
  Templates/Categories/Products the moment it's unchecked
  (`getEnabledLanguages()` in `includes/i18n.php`, vs.
  `getAvailableLanguages()` for "every file that exists"). **Enabling just
  one language removes language-switching UI entirely** - the picker only
  ever renders when more than one is enabled. At least one language always
  stays enabled; saving with none checked re-enables all of them rather
  than locking the site out.
- Visitors and admins can **switch language at any time** via the picker in
  the navbar (storefront) or sidebar (admin) - `?lang=xx` persists to
  `$_SESSION['language']` for the rest of the visit (`getCurrentLanguage()`/
  `languageSwitchUrl()`). Until they pick one, **Admin → Settings → Language**
  sets the default (`default_language`), which must itself be an enabled
  language.
- Dates are formatted per-language too (`formatLocalDate()`: `Aug 25, 2026`
  vs. `25.08.2026`).
- **Scope note**: this covers the UI chrome (navigation, labels, buttons,
  messages, emails, invoices) in both languages. **Product name, short
  description, description, and option group/value labels** (e.g. "Size" /
  "S, M, L") are translatable too - **Admin → Products → edit** has a
  language tab per available language; a language left blank falls back to
  the product's base/default-language text, per field. Under the hood, the
  `products`/`product_options`/`product_option_values` rows keep holding
  only the default-language content exactly as before; every other
  language lives in a separate `product_translations` /
  `product_option_translations` / `product_option_value_translations` row
  (`applyProductTranslation()`/`applyOptionTranslations()` in
  `includes/functions.php` overlay the visitor's language at display time).
  Storefront search and name-sorting on `index.php`/`search.php` match/sort
  against the translated text too when browsing in a non-default language.
  **Category names** are the one piece of merchant content that's still
  single-catalog (only a category's `intro_text` is per-language, same as
  before) - translating them the same way products are would be a
  reasonable follow-up if you need it. CMS pages (`pages` table) remain
  structured differently again: one whole row per language - see
  [admin/pages.php](admin/pages.php).

## Menus

**Admin → Menus** manages two independent, nestable menus:
- **Main Menu** — rendered as the navbar in `includes/header.php`, with dropdowns for any item that has children.
- **Footer Menu** — rendered as a link list in `includes/footer.php`.

Each item is a **Custom URL**, a **Category** (links to `index.php?category=`,
including that category's subcategory products), or a **Page** (links to
`page.php?slug=`). Reorder items by dragging the &#10021; handle (jQuery UI
Sortable, persisted via `admin/menu_reorder.php`) — dragging only reorders
siblings, it can never accidentally move an item under a different parent.
Nesting depth is unlimited, same as categories.

## Test users

**Admin → Customers** has a "Create Test User" form (username/email/password
you choose) that creates a customer account with `is_test_account = 1`.
Whoever logs into the storefront with that account sees a persistent
**TEST MODE** banner, and every order they place while logged in:

- uses [includes/PaymentGateway.php](includes/PaymentGateway.php)'s
  `TestGateway`, which makes **no network call to PayPal/Stripe/anywhere**
  and immediately marks the order paid (simulated) - no real money ever moves,
  regardless of which payment method they pick at checkout;
- is still recorded in `inventory_log` (so the trial run is visible and
  auditable in **Admin → Inventory**) but **never decrements real stock** -
  the matching `UPDATE products SET stock_quantity = ...` is skipped entirely;
- is tagged `orders.is_test_order = 1` and, as a result, is **excluded from
  every financial figure**: `admin/finance.php`'s revenue/refund/average-order
  totals and monthly/payment-method breakdowns, the dashboard's revenue and
  order-count cards, and the transaction ledger (test orders never get a
  `transactions` row written for them in the first place).

Test orders still show up in **Admin → Orders** and on the dashboard's
"Recent Orders" (both filterable, both marked with a `TEST` badge) so you can
actually review the trial run - they're just excluded from the numbers that
represent real business activity. Delete a test account (and stop using it)
from **Admin → Customers → [account] → Delete Test User**.

## Pagination

The frontend product grid (`index.php`, and the product results on
`search.php`) offers **20 / 50 / 200 / Show all** items per page. Once a
visitor picks one, it's written to `$_SESSION['per_page']` and applies to
every listing for the rest of their visit - no need to repeat it in the URL.
Until they pick one, the site uses the default configured in **Admin →
Settings → Product Listings** (`items_per_page_default`, ships as `20`).
Bootstrap pagination controls (`renderPagination()` in
`includes/functions.php`) appear whenever there's more than one page.

## Discounts & availability windows

Each product (**Admin → Products → edit → Discount / Availability Window**
fieldsets) can independently have:

- **A discount** - percentage or fixed amount off, optionally bounded by a
  start and/or end date/time. While active it renders as a badge (e.g. "20%
  off" or "Save €3.00") next to the price on both the product grid and the
  product page, with an "Offer valid ..." / "Offer ends ..." line whenever a
  date bound is set (`formatDiscountDateRange()` in `includes/functions.php`).
  Sorting by price on the product grid uses the currently-discounted price.
- **An availability window** - `available_from`/`available_until`. Outside
  that window the product is fully hidden: absent from listings/search *and*
  its direct URL 404s, exactly as if it didn't exist
  (`isProductCurrentlyAvailable()`). Use this to schedule a product to go
  live or expire on a specific date without touching its `status`.

## VAT

**Admin → Settings → VAT** toggles tax on/off for the whole shop
(`vat_enabled`). **Admin → Tax Rates** manages any number of rates (e.g.
"Standard 19%", "Reduced 7%"), one marked default for new products.

- **Product pricing** (Admin → Products → edit → Price & VAT): choose a tax
  rate and enter the price as **either net or gross** - a live JS hint
  shows the other figure as you type, and the server independently
  recomputes and stores the canonical **net** price (never trusts the
  client-side conversion). `products.price_entry_mode` remembers which one
  you last typed into, so the form shows it back the same way next time.
- **Frontend display**: product listings and the product page always show
  the **gross** (tax-included) price (`getGrossPrice()` in
  `includes/functions.php`), with a "Prices include VAT" note.
- **Cart/checkout**: show the **net** price plus a VAT line broken out by
  rate (a cart with items at two different rates shows two VAT lines) -
  `Cart::getItems()`'s `tax_total`/`tax_breakdown`. Net + tax always sums to
  the same total as the gross prices shown on the storefront.
- Each `order_items` row captures its `tax_rate_percent`/`tax_amount` at
  checkout time, so historical orders/invoices stay correct even if you
  later edit or delete a tax rate.
- Turning VAT off: prices are shown and charged exactly as entered
  everywhere, no tax added or broken out anywhere.

## Payments

Gateway integration points live in [includes/PaymentGateway.php](includes/PaymentGateway.php):

- **PayPal** — real Orders v2 REST calls (sandbox by default). Configure
  `PAYPAL_CLIENT_ID`/`PAYPAL_CLIENT_SECRET`/`PAYPAL_MODE` either in
  **Admin → Settings → Payment** (takes priority, stored in the `settings`
  table) or as `config/config.php` constants / `SHOPREX_PAYPAL_*` env vars
  (the fallback default for an unconfigured install). Without valid
  credentials the order is still created and marked "pending" so the rest
  of the flow keeps working for local testing.
- **Credit card** — Stripe Checkout Sessions (test mode by default). Same
  admin-settings-overrides-constants pattern for
  `STRIPE_SECRET_KEY`/`STRIPE_PUBLISHABLE_KEY`. Same pending-order fallback
  applies without real keys.
- **Bank transfer** — no external API. The order is created as "pending",
  the customer is emailed your bank details (**Admin → Settings → Shop
  Details**), and an admin marks the order "paid" in **Admin → Orders** once
  the transfer arrives.

Before going live: add PayPal webhook / Stripe webhook handling for
asynchronous confirmation (the current flow relies on the browser redirect
back to `checkout_process.php`, which is enough for a basic framework but
not bulletproof against abandoned redirects).

## Email templates

[includes/Mailer.php](includes/Mailer.php) uses PHP's built-in `mail()` by
default so the framework has zero required dependencies. Every send attempt
is logged to the `email_log` table. For real-world delivery, install
[PHPMailer](https://github.com/PHPMailer/PHPMailer) via Composer and swap
the transport in `Mailer::deliver()` for an SMTP-based send using the
`SMTP_HOST`/`SMTP_PORT`/`SMTP_USER`/`SMTP_PASS` constants already defined in
`config/config.php`.

Every email is `{{_header}}` + a template's body + `{{_footer}}`, all
editable per-language in **Admin → Email Templates**
([admin/email_templates.php](admin/email_templates.php)) with a `{{token}}`
reference shown while editing each one:

| Template | Sent when |
|---|---|
| `_header` / `_footer` | Wraps every email below (shared branding) |
| `order_confirmation` | An order is placed (checkout) - includes the itemized order table (auto-generated, not part of the editable body) and the invoice PDF as an attachment |
| `order_status_update` | Admin → Orders → an admin ticks "Email the customer" while changing status |
| `registration_welcome` | A customer registers |
| `password_reset` | Forgot-password request ([forgot_password.php](forgot_password.php) / [reset_password.php](reset_password.php)) |
| `account_deletion_warning` | The GDPR inactivity cleanup, 3 months before a dormant account is erased - see [Data protection (GDPR)](#data-protection-gdpr) |

A language with no override for a given key falls back to the English
version (`Mailer::getTemplate()`), so you only need to translate what
you've customized.

## Invoices

A PDF invoice is generated at checkout (`InvoiceGenerator::generateForOrder()`,
called from `checkout_process.php` right after the order is created) in the
order's language, and:

- **emailed** as an attachment on the order confirmation email (a
  hand-built `multipart/mixed` MIME message in `Mailer::deliver()` - no
  library needed);
- **stored** under `uploads/invoices/` (never web-accessible directly -
  see [Security](#security)) and recorded in the `invoices` table;
- **downloadable** by the customer from their order history
  ([account.php](account.php)) and by any admin from
  [admin/order_view.php](admin/order_view.php), both via
  [invoice_download.php](invoice_download.php), which checks the requester
  owns the order or is an admin before streaming the file.

The invoice itself - shop name, invoice/order number, billing address, an
itemized table, and a VAT breakdown grouped by rate when applicable - is
rendered with [includes/SimplePdf.php](includes/SimplePdf.php), a small,
dependency-free PDF writer built for this project (core Helvetica fonts via
WinAnsiEncoding, which covers German umlauts/ß and other Latin-1 text;
no images, no custom fonts, multi-page support via a simple pagination
check). It's not a general-purpose PDF library - if you need one beyond
simple invoices, swap in a Composer package like `dompdf/dompdf` instead.

## Data protection (GDPR)

- **Export**: any customer can download everything shopRex holds on them
  (profile, addresses, full order history) as JSON from **My Account →
  Export My Data** ([account_export.php](account_export.php)); an admin can
  do the same for any customer from **Admin → Customers → [customer] →
  Export Data** ([admin/customer_export.php](admin/customer_export.php)).
  Both call the same `GdprTools::exportData()`.
- **Deletion ("right to erasure")**: customers can delete their own account
  (password re-entry required, [account_delete.php](account_delete.php));
  admins can delete any customer's from **Admin → Customers → [customer] →
  Delete Account (GDPR)**. Both call `GdprTools::deleteCustomer()`, which
  hard-deletes the `customers` row (and cascades their addresses) but
  **keeps their orders** with `shipping_name`/address/notes scrubbed - a
  reading of GDPR Art. 17(3)(b), which exempts data needed for a legal
  retention obligation (accounting/tax records). Invoice PDFs already
  generated are **not** retroactively redacted (adjust
  `GdprTools::deleteCustomer()` if your jurisdiction's retention rules
  differ from this default).
- **Automated inactivity deletion**: **Admin → Settings → Data Retention**
  sets how many months of inactivity trigger deletion (default 24).
  3 months before that threshold, a customer is emailed a warning
  (`account_deletion_warning` template) - logging in at any point cancels
  it. [includes/GdprCleanup.php](includes/GdprCleanup.php)'s
  `runGdprInactivityCleanup()` does both steps; run it daily via a real
  system cron:
  ```bash
  0 3 * * * php /path/to/shopRex/admin/cron/gdpr_cleanup.php
  ```
  [admin/cron/gdpr_cleanup.php](admin/cron/gdpr_cleanup.php) refuses to run
  outside the CLI (a real cron job has no admin session to check, so
  exposing this over HTTP would let anyone trigger deletions) - it's also
  blocked by `admin/cron/.htaccess` as a second layer. Admins can also
  trigger it on demand from **Admin → Settings → Run Cleanup Now**. Test
  accounts (`is_test_account`) are never touched by any of this.

## External libraries

All loaded from public CDNs (jsdelivr / code.jquery.com) - no Composer/npm
build step required. To self-host instead (e.g. for an offline/air-gapped
deployment or stricter CSP), download each into `assets/vendor/` and update
the `<link>`/`<script>` tags in `includes/header.php`, `includes/footer.php`,
`admin/pages.php`, `admin/menus.php`, `admin/product_images.php`, and
`admin/image_crop.php`.

| Library | Used for | Where |
|---|---|---|
| Bootstrap 5.3 | Storefront layout/components | `includes/header.php`, `includes/footer.php` |
| Bootstrap Icons 1.11 | Icons (cart, search, payment methods, ...) | `includes/header.php` |
| jQuery 3.7 | Small storefront interactions; required by jQuery UI | `includes/footer.php`, `assets/js/main.js` |
| jQuery UI 1.13 (Sortable) | Drag-to-reorder menus and product images | `admin/menus.php`, `admin/product_images.php` |
| Quill.js 2.0 | Rich-text editor for CMS pages | `admin/pages.php` |
| Cropper.js 1.6 | Interactive image cropping | `admin/image_crop.php` |

## Project structure

```
install.php           First-run setup wizard (see Setup above)
config/                DB + site configuration; installed.php is generated, not committed
includes/              Shared PHP: bootstrap, Cart, Mailer, PaymentGateway, ImageProcessor (GD
                       cropping), SimplePdf + InvoiceGenerator, GdprTools + GdprCleanup, i18n
                       (__()/language files), category/menu tree + theme helpers
includes/lang/en.php, de.php   Translation strings (add a language: drop a new xx.php here)
assets/                Storefront CSS/JS/images
themes/                Installable layout packages (see Frontend theme above) - themes/default/,
                       themes/sidebar/, and any you add; each is just a theme.json plus optional
                       header.php/footer.php/home.php/style.css overrides
uploads/products/      Uploaded product images (originals + generated cropped derivatives)
uploads/invoices/      Generated invoice PDFs (never web-accessible, see Security)
sql/schema.sql         Database structure + default settings/pages/menu/tax rates/email templates/
                       a default shipping method
sql/seed_demo.sql      Optional demo categories/products/menu links (installer checkbox)
index.php, product.php, cart.php, checkout.php, page.php, search.php, ...   Storefront pages
forgot_password.php, reset_password.php   Password recovery flow
account_export.php, account_delete.php    Customer self-service GDPR export/erasure
invoice_download.php   Auth-checked invoice PDF streaming (order owner or any admin)
admin/                 Back office (own header/footer/auth/roles/CSS)
admin/includes/roles.php    Role → capability map (see Admin roles above)
admin/admins.php            Admin account management (Super Admin only)
admin/categories.php        Category tree management (unlimited nesting)
admin/pages.php              CMS page management (Quill.js editor, per language)
admin/menus.php              Main/footer menu management (jQuery UI Sortable)
admin/menu_reorder.php       AJAX endpoint backing the menu drag-reorder
admin/product_images.php     Per-product image manager (upload, caption, reorder, primary)
admin/product_image_reorder.php   AJAX endpoint backing the image drag-reorder
admin/image_crop.php         Cropper.js crop UI + triggers ImageProcessor (GD)
admin/tax_rates.php          VAT rate management
admin/shipping.php           Weight-tier shipping methods + free-shipping rules
admin/email_templates.php    Editable email header/footer/body, per language
admin/customer_export.php    Admin-triggered GDPR data export
admin/cron/gdpr_cleanup.php  CLI-only entry point for the inactivity cleanup (see Data protection)
admin/settings.php           Shop/payment/bank details, layout/theme/language/VAT/retention settings
```

## Notes / known simplifications

- CSRF protection, password hashing (`password_hash`/`password_verify`),
  and prepared statements are used throughout, but this has not had a full
  security audit — review before taking it to production, especially file
  upload handling, the admin panel's access controls, and locking down
  `install.php` (it self-locks once an admin exists, but consider removing
  it entirely after setup).
- CMS page content (`page.php`) and the storefront's rendering of it is
  **trusted HTML**, not escaped — same model as most CMSes (WordPress
  post/page content, etc.). Anyone who can edit Pages (Super Admin or
  Manager) can inject arbitrary markup/scripts into the storefront; treat
  "who can edit pages" as equivalent to "who can edit the site's code."
- Image cropping (`includes/ImageProcessor.php`) requires the PHP `gd`
  extension; without it, uploads still work but the Crop button will show
  an error instead of generating a cropped derivative.
- "Crop" produces one derivative per image (replacing the previous one on
  re-crop) — there's no responsive `srcset`/multiple-size generation.
- Test-order detection requires being **logged in** as the test account -
  there's no such thing as a "test guest checkout" (an account is what
  Admin → Customers creates, and `is_test_account` lives on that row).
- The discounted-price formula is intentionally duplicated once in SQL
  (`index.php`/`search.php`, for sorting/filtering) and once in PHP
  (`getActiveDiscount()` in `includes/functions.php`, for display and the
  authoritative price used at add-to-cart/checkout time) - if you change
  the discount math, update both.
- **Language coverage**: the storefront/admin UI chrome and all emails are
  fully translated EN/DE, CMS pages support one row per language, and
  product name/short description/description/option labels are
  per-language too (Admin → Products → edit) - see [Languages](#languages).
  Category *names* remain single-language (only a category's `intro_text`
  is translatable) - see [Languages](#languages) for why and how to extend
  that if you need it.
- **SimplePdf** (`includes/SimplePdf.php`) only supports the core Helvetica
  fonts via WinAnsiEncoding (~Latin-1/Windows-1252) - it covers English and
  German (and most Western European languages) but not, e.g., Cyrillic,
  Greek, or CJK scripts; unsupported characters get transliterated or
  dropped by the `iconv(...//TRANSLIT)` conversion. It also estimates text
  width for line-wrapping rather than using exact glyph metrics. For
  anything beyond simple invoices, swap in a Composer PDF library.
- GDPR erasure keeps order/financial records (anonymized) rather than
  deleting them outright, and does not retroactively edit already-generated
  invoice PDFs - see [Data protection (GDPR)](#data-protection-gdpr) for
  the reasoning and where to adjust it if your retention obligations differ.
- No automated test suite is included.

## Contributing

Bug reports, feature requests, and PRs are welcome - see
[CONTRIBUTING.md](CONTRIBUTING.md) for the development setup, coding
guidelines, and how to report a security issue privately.

## License

[MIT](LICENSE) - do whatever you like with it, including commercially;
just keep the copyright notice.
