-- ============================================================
-- shopRex - basic PHP/MySQL online shop framework
-- Database schema (structure + defaults only - no admin account,
-- no demo content; both are created by the install wizard).
--
-- You normally never run this by hand - visit install.php in a
-- browser and it is imported for you. Manual import (advanced/
-- unattended setups only):
--   mysql -u root -p your_db_name < sql/schema.sql
--   mysql -u root -p your_db_name < sql/seed_demo.sql   (optional demo content)
-- ============================================================

-- ------------------------------------------------------------
-- Categories (unlimited nesting via self-referencing parent_id)
-- ------------------------------------------------------------
CREATE TABLE categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    parent_id INT UNSIGNED NULL,
    name VARCHAR(150) NOT NULL,
    slug VARCHAR(160) NOT NULL UNIQUE,
    description TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE SET NULL,
    INDEX idx_categories_parent (parent_id)
) ENGINE=InnoDB;

-- Per-language overrides for a category - one row per (category_id,
-- language), same pattern the `pages` table uses for (slug, language).
-- `name` mirrors products.name/product_translations.name: categories.name
-- always holds the shop's DEFAULT-language name (unchanged, still what
-- drives the slug); this table holds every OTHER language's name, and is
-- looked up with a fallback to the default name when a translation hasn't
-- been entered yet - see Services\CategoryTreeService::overlayNames() and
-- Services\TranslationOverlay::applyToProduct() for the equivalent product
-- logic this mirrors. `intro_text` is the older field here (an optional
-- blurb shown above the product grid when browsing this category) and
-- keeps its own separate fallback-to-nothing behavior (blank is a valid
-- "no intro text" state, unlike name which always falls back to the
-- default-language name rather than ever rendering blank).
CREATE TABLE category_translations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id INT UNSIGNED NOT NULL,
    language VARCHAR(5) NOT NULL,
    name VARCHAR(150) NULL,
    intro_text TEXT NULL,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_category_lang (category_id, language)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- VAT / tax rates (Admin -> Settings -> "Enable VAT", Admin -> Tax Rates).
-- When vat_enabled=0 (settings table), rates are ignored everywhere and
-- prices are shown/charged as entered, tax-free.
-- ------------------------------------------------------------
CREATE TABLE tax_rates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    rate DECIMAL(5,2) NOT NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO tax_rates (id, name, rate, is_default) VALUES
    (1, 'Standard', 19.00, 1),
    (2, 'Reduced', 7.00, 0);

-- ------------------------------------------------------------
-- Products
-- ------------------------------------------------------------
-- discount_* configures an optional promotional price, active only within
-- discount_starts_at/discount_ends_at when set (NULL on either side means
-- unbounded in that direction). available_from/available_until control
-- whether the product is visible/reachable at all (outside that window it
-- behaves as if it didn't exist, same as status != 'active') - use this to
-- schedule a product to go live or expire on a specific date.
CREATE TABLE products (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id INT UNSIGNED NULL,
    sku VARCHAR(64) NOT NULL UNIQUE,
    name VARCHAR(200) NOT NULL,
    slug VARCHAR(220) NOT NULL UNIQUE,
    short_description VARCHAR(500) NULL,
    description TEXT NULL,
    price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    discount_type ENUM('none','percent','fixed') NOT NULL DEFAULT 'none',
    discount_value DECIMAL(10,2) NULL,
    discount_starts_at DATETIME NULL,
    discount_ends_at DATETIME NULL,
    available_from DATETIME NULL,
    available_until DATETIME NULL,
    -- price is always stored NET (before tax) - the canonical value used
    -- everywhere in calculations. price_entry_mode only remembers which
    -- field the admin last typed into (Admin -> Products -> edit), so the
    -- form can show it back to them the way they expect.
    tax_rate_id INT UNSIGNED NULL,
    price_entry_mode ENUM('net','gross') NOT NULL DEFAULT 'net',
    stock_quantity INT NOT NULL DEFAULT 0,
    stock_threshold INT NOT NULL DEFAULT 5,
    -- NULL = no cap beyond whatever stock allows (Admin -> Products -> edit).
    max_order_quantity INT UNSIGNED NULL,
    weight_kg DECIMAL(8,2) NULL,
    status ENUM('active','draft','archived') NOT NULL DEFAULT 'active',
    -- v2.00 - warranty/battery/hygiene disclosure fields (Admin -> Products
    -- -> edit). statutory_warranty_months defaults to the EU minimum (24) -
    -- the app doesn't enforce jurisdiction-specific legal minimums, this is
    -- an editable starting default like the rest of the seeded legal
    -- content (see the `pages` seed rows below). manufacturer_warranty_months
    -- NULL = no manufacturer warranty offered beyond the statutory one.
    statutory_warranty_months INT UNSIGNED NOT NULL DEFAULT 24,
    manufacturer_warranty_months INT UNSIGNED NULL,
    manufacturer_warranty_notes VARCHAR(500) NULL,
    -- Batteries Directive take-back disclosure (see the 'battery-return'
    -- page below) shown wherever this product appears in an order.
    contains_battery TINYINT(1) NOT NULL DEFAULT 0,
    -- Excludes this item from the withdrawal flow once unsealed, for
    -- health/hygiene reasons (Art. 16(e) Consumer Rights Directive /
    -- §312g Abs. 2 Nr. 3 BGB) - enforced server-side in
    -- Controllers\Storefront\WithdrawalController, not just hidden client-side.
    is_hygiene_product TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    FOREIGN KEY (tax_rate_id) REFERENCES tax_rates(id) ON DELETE SET NULL,
    INDEX idx_products_status (status),
    INDEX idx_products_price (price)
) ENGINE=InnoDB;

-- Per-language overrides for name/short_description/description - only
-- ever holds languages OTHER than the site's default language; the
-- default-language content lives on the products row itself (same
-- "base table = default language, translation table = everything else"
-- split used for category_translations.intro_text). A NULL/blank field
-- here falls back to the products column of the same name - see
-- applyProductTranslation() in includes/functions.php. One row per
-- (product, language).
CREATE TABLE product_translations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NOT NULL,
    language VARCHAR(5) NOT NULL,
    name VARCHAR(200) NULL,
    short_description VARCHAR(500) NULL,
    description TEXT NULL,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_product_lang (product_id, language)
) ENGINE=InnoDB;

-- image_path is always the original upload; cropped_path (if set) is a
-- generated derivative in the exact crop_w x crop_h dimensions chosen in
-- the back office and is what the frontend gallery/thumbnails display.
CREATE TABLE product_images (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    cropped_path VARCHAR(255) NULL,
    description VARCHAR(255) NULL,
    -- The crop SELECTION rectangle (in the original image's own pixel
    -- coordinates) - what the admin dragged out in the Cropper.js widget.
    crop_x INT UNSIGNED NULL,
    crop_y INT UNSIGNED NULL,
    crop_width INT UNSIGNED NULL,
    crop_height INT UNSIGNED NULL,
    -- The chosen OUTPUT/target size the selection above was resized to -
    -- a deliberately separate pair of columns from crop_width/crop_height
    -- above (which stays the *selection* size, not the size it was scaled
    -- to), so Controllers\Admin\ImageCropController can pre-fill the
    -- "Output Width/Height" fields with what was actually used last time
    -- when an admin reopens the crop tool, instead of the selection size.
    crop_target_width INT UNSIGNED NULL,
    crop_target_height INT UNSIGNED NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Product options, e.g. "Size", "Color"
CREATE TABLE product_options (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Values for each option, e.g. "S","M","L" or "Red","Blue"
-- stock_quantity here is legacy/unused for stock purposes once a product
-- has product_variants rows (see below) - kept only so price_modifier has
-- somewhere to live; Admin -> Products -> edit no longer writes to it.
CREATE TABLE product_option_values (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_option_id INT UNSIGNED NOT NULL,
    value VARCHAR(100) NOT NULL,
    price_modifier DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    stock_quantity INT NOT NULL DEFAULT 0,
    sku_suffix VARCHAR(32) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    FOREIGN KEY (product_option_id) REFERENCES product_options(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Per-language override for an option group's name (e.g. "Size" -> "Größe").
-- product_options/product_option_values are fully deleted and recreated on
-- every product save (admin/product_edit.php) - these translation rows are
-- always re-inserted alongside the fresh option/value rows in that same
-- request (the admin form resubmits every language's fields every save,
-- not just the active tab), so they survive a save even when the admin
-- only touched price/stock and never looked at the translations tab.
CREATE TABLE product_option_translations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_option_id INT UNSIGNED NOT NULL,
    language VARCHAR(5) NOT NULL,
    name VARCHAR(100) NOT NULL,
    FOREIGN KEY (product_option_id) REFERENCES product_options(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_option_lang (product_option_id, language)
) ENGINE=InnoDB;

-- Per-language override for one option value (e.g. "Red" -> "Rot").
CREATE TABLE product_option_value_translations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_option_value_id INT UNSIGNED NOT NULL,
    language VARCHAR(5) NOT NULL,
    value VARCHAR(100) NOT NULL,
    FOREIGN KEY (product_option_value_id) REFERENCES product_option_values(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_value_lang (product_option_value_id, language)
) ENGINE=InnoDB;

-- A "variant" is one full combination of option values (one value from
-- every option group the product has) - this is the real stock-tracking
-- unit once a product has any options, replacing the old "stock per single
-- option value, take the min() across the chosen ones" approximation (see
-- includes/Cart.php). A single-option-group product (e.g. just "Size") gets
-- one variant per size, so "individual option" and "combination" share the
-- same mechanism instead of two parallel ones.
CREATE TABLE product_variants (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NOT NULL,
    stock_quantity INT NOT NULL DEFAULT 0,
    sku_suffix VARCHAR(32) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Join table: which option value(s) make up each variant. A product with
-- two option groups (Size, Color) has two rows per variant here (one per
-- group); a product with one option group has one row per variant.
CREATE TABLE product_variant_values (
    product_variant_id INT UNSIGNED NOT NULL,
    product_option_value_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (product_variant_id, product_option_value_id),
    FOREIGN KEY (product_variant_id) REFERENCES product_variants(id) ON DELETE CASCADE,
    FOREIGN KEY (product_option_value_id) REFERENCES product_option_values(id) ON DELETE CASCADE,
    INDEX idx_product_variant_values_value (product_option_value_id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Customers
-- ------------------------------------------------------------
-- is_test_account customers (created via Admin -> Customers -> Test Users)
-- can place trial orders that behave like the real checkout flow but never
-- touch stock counts, never call a real payment gateway, and are excluded
-- from financial reporting. See orders.is_test_order.
-- language is the customer's preferred language at signup (their session
-- language then) - used to pick which language emails/invoices are sent in.
-- password_reset_*/last_login_at back the forgot-password flow and the
-- GDPR inactivity automation (see admin/cron/gdpr_cleanup.php).
CREATE TABLE customers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    -- Admin-configurable via Admin -> Numbering (Services\NumberSequenceService);
    -- NULL for any customer created before that feature existed - only new
    -- customers get one, existing rows are never backfilled.
    customer_number VARCHAR(50) NULL UNIQUE,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    phone VARCHAR(40) NULL,
    language VARCHAR(5) NOT NULL DEFAULT 'en',
    status ENUM('active','blocked') NOT NULL DEFAULT 'active',
    is_test_account TINYINT(1) NOT NULL DEFAULT 0,
    -- Grants this one customer the "purchase on invoice" payment method at
    -- checkout (Admin -> Customers -> [customer] -> Payment). Off by
    -- default for everyone - an explicit per-customer trust decision, not a
    -- site-wide payment option (see payment_method ENUM on orders/payments).
    can_pay_on_invoice TINYINT(1) NOT NULL DEFAULT 0,
    password_reset_token VARCHAR(64) NULL,
    password_reset_expires_at DATETIME NULL,
    last_login_at DATETIME NULL,
    deletion_warning_sent_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_customers_reset_token (password_reset_token)
) ENGINE=InnoDB;

CREATE TABLE customer_addresses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    type ENUM('shipping','billing') NOT NULL DEFAULT 'shipping',
    full_name VARCHAR(150) NOT NULL,
    address_line1 VARCHAR(200) NOT NULL,
    address_line2 VARCHAR(200) NULL,
    city VARCHAR(100) NOT NULL,
    state VARCHAR(100) NULL,
    postal_code VARCHAR(20) NOT NULL,
    country VARCHAR(100) NOT NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Admin users (back office). Roles are enforced in application code
-- (see admin/includes/roles.php):
--   super_admin - full access, incl. managing other admin accounts
--   manager     - product/category/inventory ("article") management only
-- The first super_admin is created by the install wizard, not here.
-- ------------------------------------------------------------
CREATE TABLE admin_users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('super_admin','manager') NOT NULL DEFAULT 'manager',
    status ENUM('active','disabled') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login DATETIME NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Brute-force throttle for login.php / admin/login.php / forgot_password.php.
-- One row per failed attempt; see isRateLimited()/recordFailedLoginAttempt()/
-- clearLoginAttempts() in includes/functions.php. Rows aren't pruned
-- automatically - they age out of every check's time window on their own,
-- but an admin can safely TRUNCATE this table at any time.
-- ------------------------------------------------------------
CREATE TABLE login_attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identifier VARCHAR(191) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_attempts_identifier_created (identifier, created_at)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Cart (persisted so a session survives across visits/devices)
-- ------------------------------------------------------------
CREATE TABLE carts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id VARCHAR(190) NOT NULL,
    customer_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
    INDEX idx_carts_session (session_id)
) ENGINE=InnoDB;

CREATE TABLE cart_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cart_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    option_value_id INT UNSIGNED NULL,
    quantity INT NOT NULL DEFAULT 1,
    unit_price DECIMAL(10,2) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cart_id) REFERENCES carts(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (option_value_id) REFERENCES product_option_values(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Shipping methods/providers (Admin -> Shipping). A customer picks one of
-- the active methods at checkout; its cost is computed from
-- shipping_weight_tiers against the cart's total weight (sum of each line's
-- products.weight_kg * quantity, NULL weight treated as 0), unless a free
-- shipping condition on the method itself is met first. See
-- Cart::calculateShippingForMethod() in includes/Cart.php for the exact
-- algorithm, including how weight beyond the highest tier is handled.
-- ------------------------------------------------------------
CREATE TABLE shipping_methods (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    -- Free shipping if EITHER condition is met (NULL = that condition is unused).
    free_shipping_min_order_value DECIMAL(10,2) NULL,
    free_shipping_min_quantity INT UNSIGNED NULL,
    -- Once the cart's weight exceeds every tier below, keep adding
    -- extra_weight_step_price on top for every extra_weight_step_kg (or part
    -- thereof) over the top tier's up_to_weight_kg. NULL = no tier above the
    -- highest one defined; heaviest tier's price is used no matter how much
    -- further over it the cart goes.
    extra_weight_step_kg DECIMAL(8,2) NULL,
    extra_weight_step_price DECIMAL(10,2) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Weight brackets for a shipping method, e.g. (up_to_weight_kg=2, price=3.90),
-- (up_to_weight_kg=5, price=5.90) - a cart weighing up to 2kg pays 3.90,
-- over 2kg and up to 5kg pays 5.90, and so on; ordered by up_to_weight_kg.
CREATE TABLE shipping_weight_tiers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    shipping_method_id INT UNSIGNED NOT NULL,
    up_to_weight_kg DECIMAL(8,2) NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    FOREIGN KEY (shipping_method_id) REFERENCES shipping_methods(id) ON DELETE CASCADE,
    INDEX idx_shipping_weight_tiers_method (shipping_method_id)
) ENGINE=InnoDB;

-- Default method equivalent to the old flat_shipping_cost/free_shipping_threshold
-- settings (see README/admin/settings.php history) - one tier covering any
-- realistic parcel weight, so a fresh install behaves the same out of the box.
INSERT INTO shipping_methods (id, name, is_active, sort_order, free_shipping_min_order_value) VALUES
    (1, 'Standard Shipping', 1, 1, 50.00);
INSERT INTO shipping_weight_tiers (shipping_method_id, up_to_weight_kg, price, sort_order) VALUES
    (1, 1000.00, 4.90, 1);

-- ------------------------------------------------------------
-- Orders
-- ------------------------------------------------------------
CREATE TABLE orders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_number VARCHAR(32) NOT NULL UNIQUE,
    customer_id INT UNSIGNED NULL,
    guest_email VARCHAR(190) NULL,
    status ENUM('pending','processing','paid','shipped','completed','cancelled','refunded') NOT NULL DEFAULT 'pending',
    -- 'invoice' (Admin -> Settings -> Payment) is only ever offered to a
    -- customer whose account has customers.can_pay_on_invoice = 1 - see
    -- checkout.php/checkout_process.php, which re-check that server-side
    -- rather than trusting the submitted payment_method.
    payment_method ENUM('paypal','credit_card','bank_transfer','invoice') NOT NULL,
    payment_status ENUM('pending','paid','failed','refunded') NOT NULL DEFAULT 'pending',
    -- Placed by an is_test_account customer: no real gateway call was made,
    -- stock was not decremented, and this order is excluded from every
    -- financial report (see admin/finance.php, admin/index.php).
    is_test_order TINYINT(1) NOT NULL DEFAULT 0,
    language VARCHAR(5) NOT NULL DEFAULT 'en',
    subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    shipping_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    -- Snapshot of the chosen method's name at order time (like order_items.product_name) -
    -- so a later-renamed/deleted method doesn't corrupt past orders' display.
    shipping_method_id INT UNSIGNED NULL,
    shipping_method_name VARCHAR(150) NULL,
    tax_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    shipping_name VARCHAR(150) NULL,
    shipping_address1 VARCHAR(200) NULL,
    shipping_address2 VARCHAR(200) NULL,
    shipping_city VARCHAR(100) NULL,
    shipping_state VARCHAR(100) NULL,
    shipping_postal_code VARCHAR(20) NULL,
    shipping_country VARCHAR(100) NULL,
    billing_same_as_shipping TINYINT(1) NOT NULL DEFAULT 1,
    billing_name VARCHAR(150) NULL,
    billing_address1 VARCHAR(200) NULL,
    billing_address2 VARCHAR(200) NULL,
    billing_city VARCHAR(100) NULL,
    billing_state VARCHAR(100) NULL,
    billing_postal_code VARCHAR(20) NULL,
    billing_country VARCHAR(100) NULL,
    customer_notes VARCHAR(500) NULL,
    admin_notes VARCHAR(500) NULL,
    -- v2.00 - stamped the first time an admin transitions this order to
    -- 'shipped' (Admin -> Orders). Models\WithdrawalRequest::calculateDeadline()
    -- uses this (falling back to created_at if never shipped) as a proxy
    -- for delivery date, since the app doesn't track real carrier
    -- delivery confirmation.
    shipped_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
    FOREIGN KEY (shipping_method_id) REFERENCES shipping_methods(id) ON DELETE SET NULL,
    INDEX idx_orders_status (status),
    INDEX idx_orders_payment_status (payment_status),
    INDEX idx_orders_is_test (is_test_order)
) ENGINE=InnoDB;

-- unit_price/total_price are NET (before tax) - tax_rate_percent and
-- tax_amount are captured at checkout time (from the product's tax rate at
-- that moment) so historical orders/invoices stay correct even if a tax
-- rate is later edited or deleted. Grouping by tax_rate_percent gives the
-- per-rate VAT breakdown shown on the invoice (see includes/InvoiceGenerator.php).
CREATE TABLE order_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NULL,
    product_name VARCHAR(200) NOT NULL,
    option_summary VARCHAR(255) NULL,
    sku VARCHAR(64) NULL,
    -- Which stock pool this line was actually tracked against at the time
    -- it was sold - a specific product_variants combination, a legacy
    -- comma-separated list of product_option_values ids, or neither (a
    -- plain product with no options). Needed so Services\OrderStockService
    -- can restock the right pool when an order is later cancelled or
    -- edited (Services\OrderEditingService) - NULL on any order placed
    -- before this column existed, which restock/edit degrades gracefully
    -- for by falling back to the plain product stock pool (see
    -- OrderEditingService's docblock).
    product_variant_id INT UNSIGNED NULL,
    option_value_ids VARCHAR(255) NULL,
    quantity INT NOT NULL DEFAULT 1,
    unit_price DECIMAL(10,2) NOT NULL,
    total_price DECIMAL(10,2) NOT NULL,
    tax_rate_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    tax_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
    FOREIGN KEY (product_variant_id) REFERENCES product_variants(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Payments (financial management)
-- ------------------------------------------------------------
CREATE TABLE payments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    -- 'invoice' (Admin -> Settings -> Payment) is only ever offered to a
    -- customer whose account has customers.can_pay_on_invoice = 1 - see
    -- checkout.php/checkout_process.php, which re-check that server-side
    -- rather than trusting the submitted payment_method.
    payment_method ENUM('paypal','credit_card','bank_transfer','invoice') NOT NULL,
    transaction_id VARCHAR(190) NULL,
    amount DECIMAL(10,2) NOT NULL,
    currency VARCHAR(10) NOT NULL DEFAULT 'EUR',
    status ENUM('pending','completed','failed','refunded') NOT NULL DEFAULT 'pending',
    gateway_response TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Simple ledger of manual/refund transactions for the finance back office
CREATE TABLE transactions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NULL,
    type ENUM('sale','refund','adjustment') NOT NULL DEFAULT 'sale',
    amount DECIMAL(10,2) NOT NULL,
    note VARCHAR(255) NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES admin_users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Inventory management log (every stock change is recorded).
-- is_test rows are written for trial orders placed by an is_test_account
-- customer so the event is still visible here for review, but - critically -
-- no matching UPDATE products SET stock_quantity=... was ever run for them,
-- so they never affect real stock counts.
-- option_value_id is legacy (pre-variants) and no longer written by new
-- code - product_variant_id is its replacement for option-combination stock
-- changes; both are nullable and mutually exclusive in practice.
-- ------------------------------------------------------------
CREATE TABLE inventory_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NOT NULL,
    option_value_id INT UNSIGNED NULL,
    product_variant_id INT UNSIGNED NULL,
    change_qty INT NOT NULL,
    reason ENUM('restock','sale','return','adjustment','cancellation') NOT NULL,
    reference VARCHAR(190) NULL,
    is_test TINYINT(1) NOT NULL DEFAULT 0,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (product_variant_id) REFERENCES product_variants(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES admin_users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Admin order create/edit/cancel audit trail (Services\OrderEditingService).
-- The order row itself is never SQL-DELETEd (see Services\GdprService's
-- accounting/tax-retention reasoning) - "delete" in the admin UI means
-- cancel + restock, logged here like every other order edit. This table
-- exists specifically because line-item edits are allowed on orders that
-- are already paid/invoiced, which can retroactively change what Finance/
-- Dashboard report - this is what keeps that honest: the live totals can
-- change, but who changed them and why stays on the record.
-- ------------------------------------------------------------
CREATE TABLE order_edit_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    admin_id INT UNSIGNED NULL,
    action ENUM('created','items_edited','cancelled') NOT NULL,
    summary TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (admin_id) REFERENCES admin_users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Email notification log
-- ------------------------------------------------------------
CREATE TABLE email_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    recipient VARCHAR(190) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    template VARCHAR(100) NOT NULL,
    order_id INT UNSIGNED NULL,
    status ENUM('sent','failed') NOT NULL DEFAULT 'sent',
    error_message VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Editable email templates (Admin -> Email Templates). template_key '_header'
-- and '_footer' are the shared wrapper every email is rendered inside;
-- the rest (order_confirmation, order_status_update, registration_welcome,
-- password_reset, account_deletion_warning) are the per-email body text.
-- Each exists once per language; Mailer::renderTemplate() falls back to
-- English if the visitor's language has no override for a given key.
-- Body text supports {{token}} placeholders, documented per-template in
-- admin/email_templates.php.
-- ------------------------------------------------------------
CREATE TABLE email_templates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    template_key VARCHAR(50) NOT NULL,
    language VARCHAR(5) NOT NULL,
    subject VARCHAR(255) NULL,
    body_html LONGTEXT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_template_lang (template_key, language)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Generated PDF invoices (one per order, created at checkout - see
-- includes/InvoiceGenerator.php). Accessible to the owning customer
-- (account.php) and any admin (admin/order_view.php) via invoice_download.php.
-- ------------------------------------------------------------
CREATE TABLE invoices (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    invoice_number VARCHAR(32) NOT NULL UNIQUE,
    language VARCHAR(5) NOT NULL DEFAULT 'en',
    pdf_path VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Site settings (key/value store used by the back office)
-- ------------------------------------------------------------
CREATE TABLE settings (
    setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
    setting_value TEXT NULL
) ENGINE=InnoDB;

INSERT INTO settings (setting_key, setting_value) VALUES
    ('shop_name', 'shopRex'),
    ('shop_email', 'shop@example.com'),
    ('currency', 'EUR'),
    ('currency_symbol', '€'),
    ('flat_shipping_cost', '4.90'),
    ('free_shipping_threshold', '50.00'),
    ('bank_account_holder', 'shopRex GmbH'),
    ('bank_iban', 'DE00 0000 0000 0000 0000 00'),
    ('bank_bic', 'XXXXXXXX'),
    ('bank_name', 'Musterbank'),
    ('site_theme', 'default'),
    ('items_per_page_default', '20'),
    ('default_language', 'en'),
    ('vat_enabled', '1'),
    ('gdpr_inactivity_months', '24'),
    -- Which payment methods checkout.php offers at all (Admin -> Settings
    -- -> Payment). 'invoice' has no on/off switch here - it's controlled
    -- entirely per-customer instead (customers.can_pay_on_invoice).
    ('payment_method_paypal_enabled', '1'),
    ('payment_method_credit_card_enabled', '1'),
    ('payment_method_bank_transfer_enabled', '1');

-- ------------------------------------------------------------
-- Editable CMS pages (Legal Notice, Privacy Policy, About Us, Copyright, ...)
-- is_system pages can have their content edited but not their slug/deleted,
-- since the footer and legal requirements depend on those slugs existing.
-- ------------------------------------------------------------
-- Each slug can have one row per language (e.g. 'legal-notice'/'en' and
-- 'legal-notice'/'de') - page.php looks up by slug+visitor language, falling
-- back to the site default language, then to any row for that slug, so a
-- missing translation degrades gracefully instead of 404ing.
CREATE TABLE pages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(160) NOT NULL,
    language VARCHAR(5) NOT NULL DEFAULT 'en',
    title VARCHAR(200) NOT NULL,
    content LONGTEXT NULL,
    is_system TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_page_slug_lang (slug, language)
) ENGINE=InnoDB;

INSERT INTO pages (slug, language, title, content, is_system) VALUES
    ('legal-notice', 'en', 'Legal Notice',
     '<p><strong>Placeholder content - replace with your actual legal notice / Impressum before going live.</strong></p>
      <p>Company name<br>Street address<br>Postal code, City<br>Country</p>
      <p>Represented by: ...<br>Contact: shop@example.com</p>
      <p>Commercial register: ...<br>VAT ID: ...</p>', 1),
    ('legal-notice', 'de', 'Impressum',
     '<p><strong>Platzhalterinhalt - vor dem Livegang durch Ihr tatsächliches Impressum ersetzen.</strong></p>
      <p>Firmenname<br>Straße und Hausnummer<br>Postleitzahl, Ort<br>Land</p>
      <p>Vertreten durch: ...<br>Kontakt: shop@example.com</p>
      <p>Handelsregister: ...<br>USt-IdNr.: ...</p>', 1),
    ('privacy-policy', 'en', 'Privacy Policy',
     '<p><strong>Placeholder content - replace with your actual privacy policy before going live.</strong></p>
      <p>Describe what personal data you collect (e.g. name, address, email, order history,
      payment details), why you collect it, how long you keep it, which third parties
      (payment providers, shipping carriers, email service) receive it, and how customers
      can exercise their rights (access, correction, deletion).</p>', 1),
    ('privacy-policy', 'de', 'Datenschutzerklärung',
     '<p><strong>Platzhalterinhalt - vor dem Livegang durch Ihre tatsächliche Datenschutzerklärung ersetzen.</strong></p>
      <p>Beschreiben Sie, welche personenbezogenen Daten Sie erheben (z. B. Name, Adresse, E-Mail,
      Bestellverlauf, Zahlungsdaten), warum Sie sie erheben, wie lange Sie sie speichern, welche
      Dritten (Zahlungsdienstleister, Versanddienstleister, E-Mail-Dienst) sie erhalten, und wie
      Kunden ihre Rechte (Auskunft, Berichtigung, Löschung) wahrnehmen können.</p>', 1),
    ('about-us', 'en', 'About Us',
     '<p>Tell your customers who you are, what you sell, and why they should trust you.
      Replace this placeholder with your own story.</p>', 1),
    ('about-us', 'de', 'Über uns',
     '<p>Erzählen Sie Ihren Kunden, wer Sie sind, was Sie verkaufen und warum sie Ihnen vertrauen sollten.
      Ersetzen Sie diesen Platzhalter durch Ihre eigene Geschichte.</p>', 1),
    ('copyright', 'en', 'Copyright',
     '<p>All content on this website - including text, images, logos, and product photography -
      is the property of this shop or its licensors and may not be reproduced, distributed,
      or used without prior written permission. Replace this placeholder with your own
      copyright statement.</p>', 1),
    ('copyright', 'de', 'Urheberrecht',
     '<p>Alle Inhalte dieser Website - einschließlich Text, Bilder, Logos und Produktfotografie -
      sind Eigentum dieses Shops oder seiner Lizenzgeber und dürfen ohne vorherige schriftliche
      Genehmigung weder vervielfältigt noch verbreitet oder verwendet werden. Ersetzen Sie diesen
      Platzhalter durch Ihre eigene Urheberrechtserklärung.</p>', 1),
    ('legal-notice', 'fr', 'Mentions légales',
     '<p><strong>Contenu provisoire - à remplacer par vos véritables mentions légales avant la mise en ligne.</strong></p>
      <p>Raison sociale<br>Adresse<br>Code postal, Ville<br>Pays</p>
      <p>Représenté par : ...<br>Contact : shop@example.com</p>
      <p>RCS : ...<br>N° TVA intracommunautaire : ...</p>', 1),
    ('privacy-policy', 'fr', 'Politique de confidentialité',
     '<p><strong>Contenu provisoire - à remplacer par votre véritable politique de confidentialité avant la mise en ligne.</strong></p>
      <p>Décrivez quelles données personnelles vous collectez (par ex. nom, adresse, e-mail, historique
      des commandes, coordonnées de paiement), pourquoi vous les collectez, pendant combien de temps
      vous les conservez, quels tiers (prestataires de paiement, transporteurs, service d''e-mail) les
      reçoivent, et comment les clients peuvent exercer leurs droits (accès, rectification,
      suppression).</p>', 1),
    ('about-us', 'fr', 'À propos de nous',
     '<p>Dites à vos clients qui vous êtes, ce que vous vendez et pourquoi ils devraient vous faire
      confiance. Remplacez ce contenu provisoire par votre propre histoire.</p>', 1),
    ('copyright', 'fr', 'Droits d''auteur',
     '<p>Tout le contenu de ce site - textes, images, logos et photographies de produits compris -
      est la propriété de cette boutique ou de ses concédants et ne peut être reproduit, distribué
      ou utilisé sans autorisation écrite préalable. Remplacez ce contenu provisoire par votre
      propre mention de droits d''auteur.</p>', 1);

-- ------------------------------------------------------------
-- Default email templates (Admin -> Email Templates). '_header'/'_footer'
-- wrap every email; the rest are per-email body text. {{tokens}} are
-- replaced by Mailer::renderTemplate() - see admin/email_templates.php for
-- the documented token list per template.
-- ------------------------------------------------------------
INSERT INTO email_templates (template_key, language, subject, body_html) VALUES
    ('_header', 'en', NULL, '<div style="background:#1f2937;color:#fff;padding:20px 24px;"><h1 style="margin:0;font-size:20px;">{{shop_name}}</h1></div><div style="padding:24px;">'),
    ('_header', 'de', NULL, '<div style="background:#1f2937;color:#fff;padding:20px 24px;"><h1 style="margin:0;font-size:20px;">{{shop_name}}</h1></div><div style="padding:24px;">'),
    ('_footer', 'en', NULL, '<p style="margin-top:24px;color:#666;font-size:13px;">If you have any questions, just reply to this email.</p></div>'),
    ('_footer', 'de', NULL, '<p style="margin-top:24px;color:#666;font-size:13px;">Bei Fragen antworten Sie einfach auf diese E-Mail.</p></div>'),

    ('order_confirmation', 'en', 'Your {{shop_name}} order {{order_number}} has been received',
     '<h2 style="margin-top:0;">Thanks for your order, {{customer_name}}!</h2><p>We''ve received order <strong>{{order_number}}</strong> and will process it shortly. You''ll receive another email as soon as it ships.</p>{{order_items_table}}{{bank_transfer_details}}'),
    ('order_confirmation', 'de', 'Ihre {{shop_name}} Bestellung {{order_number}} ist eingegangen',
     '<h2 style="margin-top:0;">Vielen Dank für Ihre Bestellung, {{customer_name}}!</h2><p>Wir haben Ihre Bestellung <strong>{{order_number}}</strong> erhalten und werden sie in Kürze bearbeiten. Sobald sie versendet wird, erhalten Sie eine weitere E-Mail.</p>{{order_items_table}}{{bank_transfer_details}}'),

    ('invoice_resend', 'en', 'Your invoice for {{shop_name}} order {{order_number}}',
     '<h2 style="margin-top:0;">Here is your invoice, {{customer_name}}</h2><p>As requested, please find attached invoice <strong>{{invoice_number}}</strong> for order <strong>{{order_number}}</strong>.</p>'),
    ('invoice_resend', 'de', 'Ihre Rechnung zu {{shop_name}} Bestellung {{order_number}}',
     '<h2 style="margin-top:0;">Hier ist Ihre Rechnung, {{customer_name}}</h2><p>Wie gewünscht finden Sie anbei die Rechnung <strong>{{invoice_number}}</strong> zu Ihrer Bestellung <strong>{{order_number}}</strong>.</p>'),

    ('order_status_update', 'en', 'Update on your {{shop_name}} order {{order_number}}',
     '<h2 style="margin-top:0;">Your order status has changed</h2><p>Order <strong>{{order_number}}</strong> is now:</p><p style="font-size:18px;font-weight:bold;text-transform:uppercase;color:#1f2937;">{{status}}</p>{{admin_notes}}'),
    ('order_status_update', 'de', 'Update zu Ihrer {{shop_name}} Bestellung {{order_number}}',
     '<h2 style="margin-top:0;">Ihr Bestellstatus hat sich geändert</h2><p>Bestellung <strong>{{order_number}}</strong> hat jetzt den Status:</p><p style="font-size:18px;font-weight:bold;text-transform:uppercase;color:#1f2937;">{{status}}</p>{{admin_notes}}'),

    ('registration_welcome', 'en', 'Welcome to {{shop_name}}, {{customer_name}}!',
     '<h2 style="margin-top:0;">Welcome, {{customer_name}}!</h2><p>Your account at {{shop_name}} has been created. You can now sign in anytime to check your order history and account details.</p><p><a href="{{account_url}}">Go to your account</a></p>'),
    ('registration_welcome', 'de', 'Willkommen bei {{shop_name}}, {{customer_name}}!',
     '<h2 style="margin-top:0;">Willkommen, {{customer_name}}!</h2><p>Ihr Konto bei {{shop_name}} wurde erstellt. Sie können sich jederzeit anmelden, um Ihren Bestellverlauf und Ihre Kontodaten einzusehen.</p><p><a href="{{account_url}}">Zu meinem Konto</a></p>'),

    ('password_reset', 'en', 'Reset your {{shop_name}} password',
     '<h2 style="margin-top:0;">Password reset requested</h2><p>Hi {{customer_name}}, click the link below to choose a new password. This link expires in 1 hour.</p><p><a href="{{reset_link}}">Reset my password</a></p><p style="color:#666;font-size:13px;">If you didn''t request this, you can safely ignore this email.</p>'),
    ('password_reset', 'de', 'Setzen Sie Ihr {{shop_name}} Passwort zurück',
     '<h2 style="margin-top:0;">Passwort zurücksetzen angefordert</h2><p>Hallo {{customer_name}}, klicken Sie auf den folgenden Link, um ein neues Passwort zu wählen. Dieser Link ist 1 Stunde gültig.</p><p><a href="{{reset_link}}">Mein Passwort zurücksetzen</a></p><p style="color:#666;font-size:13px;">Falls Sie dies nicht angefordert haben, können Sie diese E-Mail ignorieren.</p>'),

    ('account_deletion_warning', 'en', 'Your {{shop_name}} account will be deleted soon',
     '<h2 style="margin-top:0;">Your account is scheduled for deletion</h2><p>Hi {{customer_name}}, your account has been inactive for a while. In line with our data retention policy, it - along with your order history - will be automatically deleted on <strong>{{deletion_date}}</strong> unless you sign in before then.</p><p><a href="{{login_url}}">Sign in to keep your account</a></p>'),
    ('account_deletion_warning', 'de', 'Ihr {{shop_name}} Konto wird bald gelöscht',
     '<h2 style="margin-top:0;">Ihr Konto wird demnächst gelöscht</h2><p>Hallo {{customer_name}}, Ihr Konto war längere Zeit inaktiv. Gemäß unserer Datenaufbewahrungsrichtlinie wird es zusammen mit Ihrem Bestellverlauf am <strong>{{deletion_date}}</strong> automatisch gelöscht, sofern Sie sich nicht vorher anmelden.</p><p><a href="{{login_url}}">Jetzt anmelden, um Ihr Konto zu behalten</a></p>');

-- ------------------------------------------------------------
-- Main navigation and footer submenu (both editable in the back office).
-- link_type/link_value together decide the URL:
--   custom   -> link_value is used verbatim as the href
--   category -> link_value is a categories.id
--   page     -> link_value is a pages.slug
-- parent_id nests items (dropdown in the main menu, grouped column in the footer).
-- ------------------------------------------------------------
CREATE TABLE menu_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    location ENUM('main','footer') NOT NULL,
    parent_id INT UNSIGNED NULL,
    label VARCHAR(150) NOT NULL,
    link_type ENUM('custom','category','page') NOT NULL DEFAULT 'custom',
    link_value VARCHAR(255) NOT NULL DEFAULT '',
    open_new_tab TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    FOREIGN KEY (parent_id) REFERENCES menu_items(id) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT INTO menu_items (location, label, link_type, link_value, sort_order) VALUES
    ('main', 'Home', 'custom', '', 1),
    ('main', 'About Us', 'page', 'about-us', 2),
    ('footer', 'About Us', 'page', 'about-us', 1),
    ('footer', 'Legal Notice', 'page', 'legal-notice', 2),
    ('footer', 'Privacy Policy', 'page', 'privacy-policy', 3),
    ('footer', 'Copyright', 'page', 'copyright', 4),
    -- v2.00 additions - see the tables/seed data below.
    ('footer', 'Contact', 'custom', 'contact', 5),
    ('footer', 'Terms & Conditions', 'page', 'terms-conditions', 6),
    ('footer', 'Right of Withdrawal', 'page', 'right-of-withdrawal', 7);

-- ================================================================
-- v2.00 additions - OOP rewrite + legal/compliance features. See
-- CHANGELOG.md and CLAUDE.md for the full architecture writeup.
-- ================================================================

-- ------------------------------------------------------------
-- New settings (Admin -> Settings -> Company / Legal, and -> Withdrawal).
-- Company fields are blank by default and only appear on invoices/legal
-- pages once filled in (see Services\CheckoutService's InvoiceGenerator
-- call and the 'legal-notice'/'right-of-withdrawal' pages) - same
-- graceful-degradation pattern as the existing bank_* settings.
-- ------------------------------------------------------------
INSERT INTO settings (setting_key, setting_value) VALUES
    ('vat_id', ''),
    ('company_registration_number', ''),
    ('company_legal_name', ''),
    -- Right-of-withdrawal deadline = shipped_at (or created_at if never
    -- shipped) + assumed_delivery_days_after_shipment + withdrawal_period_days.
    -- See Models\WithdrawalRequest::calculateDeadline()'s docblock for why
    -- this is a pragmatic proxy, not a claim of exact legal precision.
    ('withdrawal_period_days', '14'),
    ('assumed_delivery_days_after_shipment', '3');

-- ------------------------------------------------------------
-- New CMS pages (placeholder-labeled the same way as legal-notice/
-- privacy-policy above - a starting template, not certified legal advice).
-- right-of-withdrawal carries genuine EU-model structure/text (the 14-day
-- right, how to exercise it, the model cancellation form, the hygiene-
-- goods exception) since that much is fairly standardized boilerplate,
-- but still needs the shop's real name/address/contact filled in before
-- going live.
-- ------------------------------------------------------------
INSERT INTO pages (slug, language, title, content, is_system) VALUES
    ('terms-conditions', 'en', 'Terms & Conditions',
     '<p><strong>Placeholder content - replace with your actual Terms &amp; Conditions before going live.</strong></p>
      <p>A typical shop''s terms cover: contract formation (when an order becomes binding), prices and payment
      methods, delivery terms and timelines, retention of title until payment is received, warranty/liability terms,
      and how disputes are resolved. Have this reviewed by a qualified professional for your jurisdiction.</p>', 1),
    ('terms-conditions', 'de', 'Allgemeine Geschäftsbedingungen',
     '<p><strong>Platzhalterinhalt - vor dem Livegang durch Ihre tatsächlichen AGB ersetzen.</strong></p>
      <p>Typische AGB umfassen: Vertragsschluss (wann eine Bestellung verbindlich wird), Preise und Zahlungsarten,
      Lieferbedingungen und -fristen, Eigentumsvorbehalt bis zur vollständigen Zahlung, Gewährleistung/Haftung, sowie
      die Beilegung von Streitigkeiten. Lassen Sie dies von einer fachkundigen Person für Ihre Rechtsordnung prüfen.</p>', 1),
    ('terms-conditions', 'fr', 'Conditions générales de vente',
     '<p><strong>Contenu provisoire - à remplacer par vos véritables conditions générales de vente avant la mise en ligne.</strong></p>
      <p>Des CGV type couvrent : la formation du contrat (quand une commande devient contraignante), les prix et
      moyens de paiement, les conditions et délais de livraison, la réserve de propriété jusqu''au paiement complet,
      la garantie/responsabilité, et le règlement des litiges. Faites relire ce contenu par un professionnel qualifié
      pour votre juridiction.</p>', 1),

    ('right-of-withdrawal', 'en', 'Right of Withdrawal',
     '<p><strong>Template content based on the standard EU model instructions - replace the bracketed details with
      your own and have it reviewed before going live.</strong></p>
      <h3>Right of Withdrawal</h3>
      <p>You have the right to withdraw from this contract within 14 days without giving any reason. The withdrawal
      period will expire 14 days from the day on which you (or a third party you named) acquire physical possession
      of the goods. To exercise the right of withdrawal, you must inform us ([Company name], [Address], [Email]) of
      your decision by a clear statement (e.g. a letter sent by post or email), or by using the withdrawal request
      form available from your account order history. To meet the withdrawal deadline, it is sufficient for you to
      send your communication concerning your exercise of the right of withdrawal before the withdrawal period has
      expired.</p>
      <h3>Effects of Withdrawal</h3>
      <p>If you withdraw from this contract, we will reimburse all payments received from you, including delivery
      costs (except for supplementary costs from choosing a delivery type other than the least expensive standard
      delivery we offer), without undue delay and in any event not later than 14 days from the day on which we are
      informed of your decision to withdraw. You will have to bear the direct cost of returning the goods.</p>
      <h3>Exceptions</h3>
      <p>The right of withdrawal does not apply to goods that are not suitable for return due to health protection
      or hygiene reasons once unsealed after delivery (products flagged as such are marked at checkout and in your
      order history).</p>
      <h3>Model Withdrawal Form</h3>
      <p>(complete and submit this form only if you wish to withdraw from the contract) - or use the withdrawal
      request form linked from your order in your account.<br>
      To [Company name, Address, Email]:<br>
      I/We hereby give notice that I/We withdraw from my/our contract of sale of the following goods:
      [description of goods], ordered on [date], received on [date].<br>
      Name of consumer(s), address, date.</p>', 1),
    ('right-of-withdrawal', 'de', 'Widerrufsrecht',
     '<p><strong>Vorlage auf Basis der EU-Muster-Widerrufsbelehrung - Angaben in Klammern durch Ihre eigenen ersetzen
      und vor dem Livegang prüfen lassen.</strong></p>
      <h3>Widerrufsrecht</h3>
      <p>Sie haben das Recht, binnen vierzehn Tagen ohne Angabe von Gründen diesen Vertrag zu widerrufen. Die
      Widerrufsfrist beträgt vierzehn Tage ab dem Tag, an dem Sie oder ein von Ihnen benannter Dritter die Waren in
      Besitz genommen haben. Um Ihr Widerrufsrecht auszuüben, müssen Sie uns ([Firmenname], [Adresse], [E-Mail])
      mittels einer eindeutigen Erklärung (z. B. Brief oder E-Mail) über Ihren Entschluss informieren, oder das
      Widerrufsformular in Ihrem Kundenkonto bei der jeweiligen Bestellung nutzen. Zur Wahrung der Widerrufsfrist
      reicht es aus, dass Sie die Mitteilung vor Ablauf der Widerrufsfrist absenden.</p>
      <h3>Folgen des Widerrufs</h3>
      <p>Wenn Sie diesen Vertrag widerrufen, erstatten wir Ihnen alle Zahlungen, die wir von Ihnen erhalten haben,
      einschließlich der Lieferkosten (mit Ausnahme der zusätzlichen Kosten, die sich daraus ergeben, dass Sie eine
      andere Art der Lieferung als die von uns angebotene, günstigste Standardlieferung gewählt haben), unverzüglich
      und spätestens binnen vierzehn Tagen ab dem Tag, an dem die Mitteilung über Ihren Widerruf bei uns eingegangen
      ist. Sie tragen die unmittelbaren Kosten der Rücksendung der Waren.</p>
      <h3>Ausnahmen</h3>
      <p>Das Widerrufsrecht besteht nicht bei Waren, die aus Gründen des Gesundheitsschutzes oder der Hygiene nicht
      zur Rückgabe geeignet sind, wenn ihre Versiegelung nach der Lieferung entfernt wurde (entsprechend
      gekennzeichnete Produkte werden beim Checkout und in Ihrer Bestellhistorie markiert).</p>
      <h3>Muster-Widerrufsformular</h3>
      <p>(wenn Sie den Vertrag widerrufen wollen, füllen Sie dieses Formular aus und senden Sie es zurück) - oder
      nutzen Sie das Widerrufsformular bei Ihrer Bestellung im Kundenkonto.<br>
      An [Firmenname, Adresse, E-Mail]:<br>
      Hiermit widerrufe(n) ich/wir den von mir/uns abgeschlossenen Vertrag über den Kauf der folgenden Waren:
      [Bezeichnung der Waren], bestellt am [Datum], erhalten am [Datum].<br>
      Name der Verbraucher, Anschrift, Datum.</p>', 1),
    ('right-of-withdrawal', 'fr', 'Droit de rétractation',
     '<p><strong>Modèle basé sur les instructions types de l''UE - remplacez les mentions entre crochets par les
      vôtres et faites relire ce contenu avant la mise en ligne.</strong></p>
      <h3>Droit de rétractation</h3>
      <p>Vous disposez d''un délai de 14 jours pour exercer votre droit de rétractation sans avoir à justifier de
      motifs. Le délai de rétractation expire 14 jours après le jour où vous (ou un tiers que vous avez désigné)
      prenez physiquement possession des biens. Pour exercer ce droit, vous devez nous notifier ([Raison sociale],
      [Adresse], [E-mail]) votre décision au moyen d''une déclaration dénuée d''ambiguïté (lettre ou e-mail), ou en
      utilisant le formulaire de rétractation disponible depuis votre compte client. Pour respecter le délai, il
      suffit que vous transmettiez votre communication avant l''expiration du délai de rétractation.</p>
      <h3>Effets de la rétractation</h3>
      <p>En cas de rétractation, nous vous remboursons tous les paiements reçus, y compris les frais de livraison
      (à l''exception des frais supplémentaires découlant du choix d''un mode de livraison autre que le mode standard
      le moins coûteux que nous proposons), sans retard excessif et au plus tard 14 jours à compter du jour où nous
      sommes informés de votre décision de rétractation. Les frais directs de renvoi des biens restent à votre
      charge.</p>
      <h3>Exceptions</h3>
      <p>Le droit de rétractation ne s''applique pas aux biens descellés après livraison qui ne peuvent être renvoyés
      pour des raisons d''hygiène ou de protection de la santé (les produits concernés sont signalés lors du
      paiement et dans votre historique de commandes).</p>
      <h3>Formulaire type de rétractation</h3>
      <p>(veuillez compléter et renvoyer ce formulaire uniquement si vous souhaitez vous rétracter) - ou utilisez le
      formulaire de rétractation lié à votre commande dans votre compte.<br>
      À l''attention de [Raison sociale, Adresse, E-mail] :<br>
      Je/nous vous notifie/notifions par la présente ma/notre rétractation du contrat portant sur la vente du bien
      suivant : [désignation du bien], commandé le [date], reçu le [date].<br>
      Nom du/des consommateur(s), adresse, date.</p>', 1),

    ('battery-return', 'en', 'Battery Take-Back',
     '<p><strong>Placeholder content - replace with your actual battery take-back arrangements before going live.</strong></p>
      <p>Products marked as containing a battery can be returned free of charge, separately from any other waste,
      either to [our stores/collection point address] or to any public battery collection point near you. Batteries
      must not be disposed of with household waste - the crossed-out wheeled bin symbol on the battery or its
      packaging indicates this. Returning used batteries helps prevent environmental harm from the substances they
      contain.</p>', 1),
    ('battery-return', 'de', 'Batterie-Rücknahme',
     '<p><strong>Platzhalterinhalt - vor dem Livegang durch Ihre tatsächlichen Rücknahmeregelungen ersetzen.</strong></p>
      <p>Produkte, die als batteriehaltig gekennzeichnet sind, können unentgeltlich getrennt vom sonstigen Abfall
      zurückgegeben werden - entweder an [unsere Verkaufsstelle/Sammeladresse] oder an jeder öffentlichen
      Batteriesammelstelle in Ihrer Nähe. Batterien dürfen nicht mit dem Hausmüll entsorgt werden - das Symbol der
      durchgestrichenen Mülltonne auf der Batterie oder deren Verpackung weist darauf hin. Die Rückgabe gebrauchter
      Batterien hilft, Umweltschäden durch die enthaltenen Stoffe zu vermeiden.</p>', 1),
    ('battery-return', 'fr', 'Reprise des piles et batteries',
     '<p><strong>Contenu provisoire - à remplacer par vos véritables modalités de reprise avant la mise en ligne.</strong></p>
      <p>Les produits signalés comme contenant une pile ou une batterie peuvent être rapportés gratuitement,
      séparément des autres déchets, soit à [notre magasin/point de collecte] soit à tout point de collecte public
      près de chez vous. Les piles et batteries ne doivent pas être jetées avec les ordures ménagères - le symbole
      de la poubelle barrée figurant sur la pile ou son emballage l''indique. Le retour des piles usagées contribue
      à prévenir les dommages environnementaux causés par les substances qu''elles contiennent.</p>', 1);

-- ------------------------------------------------------------
-- New email templates (Admin -> Email Templates). Seeded en/de only -
-- same fallback-to-English-for-fr gap the existing keys already have
-- (see includes/lang/fr.php vs. the email_templates table, unrelated
-- mechanisms - Mailer::getTemplate() falls back to 'en' when a language
-- has no override for a key, so this isn't a regression).
-- ------------------------------------------------------------
INSERT INTO email_templates (template_key, language, subject, body_html) VALUES
    ('contact_form_notify_shop', 'en', 'New contact message from {{name}}',
     '<h2 style="margin-top:0;">New contact form submission</h2><p><strong>From:</strong> {{name}} ({{email}})</p><p><strong>Subject:</strong> {{subject}}</p><p>{{message}}</p>'),
    ('contact_form_notify_shop', 'de', 'Neue Kontaktnachricht von {{name}}',
     '<h2 style="margin-top:0;">Neue Kontaktformular-Nachricht</h2><p><strong>Von:</strong> {{name}} ({{email}})</p><p><strong>Betreff:</strong> {{subject}}</p><p>{{message}}</p>'),
    ('contact_form_confirmation', 'en', 'We received your message - {{shop_name}}',
     '<h2 style="margin-top:0;">Thanks for reaching out, {{name}}!</h2><p>We''ve received your message and will get back to you as soon as possible.</p>'),
    ('contact_form_confirmation', 'de', 'Wir haben Ihre Nachricht erhalten - {{shop_name}}',
     '<h2 style="margin-top:0;">Danke für Ihre Nachricht, {{name}}!</h2><p>Wir haben Ihre Nachricht erhalten und melden uns so schnell wie möglich bei Ihnen.</p>'),

    ('withdrawal_request_received', 'en', 'We received your withdrawal request for order {{order_number}}',
     '<h2 style="margin-top:0;">Withdrawal request received</h2><p>Hi {{customer_name}}, we''ve received your request to withdraw from order <strong>{{order_number}}</strong>. We''ll review it and get back to you shortly.</p>'),
    ('withdrawal_request_received', 'de', 'Wir haben Ihren Widerruf zu Bestellung {{order_number}} erhalten',
     '<h2 style="margin-top:0;">Widerruf erhalten</h2><p>Hallo {{customer_name}}, wir haben Ihren Widerruf zur Bestellung <strong>{{order_number}}</strong> erhalten und melden uns in Kürze bei Ihnen.</p>'),
    ('withdrawal_request_notify_shop', 'en', 'Withdrawal request for order {{order_number}}',
     '<h2 style="margin-top:0;">New withdrawal request</h2><p>Order <strong>{{order_number}}</strong> - reason given: {{reason}}</p>'),
    ('withdrawal_request_notify_shop', 'de', 'Widerruf zu Bestellung {{order_number}}',
     '<h2 style="margin-top:0;">Neuer Widerruf</h2><p>Bestellung <strong>{{order_number}}</strong> - angegebener Grund: {{reason}}</p>'),
    ('withdrawal_request_approved', 'en', 'Your withdrawal request for order {{order_number}} was approved',
     '<h2 style="margin-top:0;">Withdrawal approved</h2><p>Hi {{customer_name}}, your withdrawal for order <strong>{{order_number}}</strong> has been approved. {{admin_notes}}</p>'),
    ('withdrawal_request_approved', 'de', 'Ihr Widerruf zu Bestellung {{order_number}} wurde genehmigt',
     '<h2 style="margin-top:0;">Widerruf genehmigt</h2><p>Hallo {{customer_name}}, Ihr Widerruf zur Bestellung <strong>{{order_number}}</strong> wurde genehmigt. {{admin_notes}}</p>'),
    ('withdrawal_request_rejected', 'en', 'Your withdrawal request for order {{order_number}} was rejected',
     '<h2 style="margin-top:0;">Withdrawal rejected</h2><p>Hi {{customer_name}}, your withdrawal for order <strong>{{order_number}}</strong> was rejected. {{admin_notes}}</p>'),
    ('withdrawal_request_rejected', 'de', 'Ihr Widerruf zu Bestellung {{order_number}} wurde abgelehnt',
     '<h2 style="margin-top:0;">Widerruf abgelehnt</h2><p>Hallo {{customer_name}}, Ihr Widerruf zur Bestellung <strong>{{order_number}}</strong> wurde abgelehnt. {{admin_notes}}</p>'),

    ('rma_ticket_received', 'en', 'We received your defect report for order {{order_number}}',
     '<h2 style="margin-top:0;">Defect report received</h2><p>Hi {{customer_name}}, we''ve received your report for <strong>{{product_name}}</strong> from order {{order_number}}. We''ll review it and get back to you.</p>'),
    ('rma_ticket_received', 'de', 'Wir haben Ihre Mängelmeldung zu Bestellung {{order_number}} erhalten',
     '<h2 style="margin-top:0;">Mängelmeldung erhalten</h2><p>Hallo {{customer_name}}, wir haben Ihre Meldung zu <strong>{{product_name}}</strong> aus Bestellung {{order_number}} erhalten und melden uns bei Ihnen.</p>'),
    ('rma_ticket_notify_shop', 'en', 'Defect report for order {{order_number}}',
     '<h2 style="margin-top:0;">New RMA ticket</h2><p>Order <strong>{{order_number}}</strong>, product: {{product_name}}, claim type: {{warranty_claim_type}}</p><p>{{defect_description}}</p>'),
    ('rma_ticket_notify_shop', 'de', 'Mängelmeldung zu Bestellung {{order_number}}',
     '<h2 style="margin-top:0;">Neues RMA-Ticket</h2><p>Bestellung <strong>{{order_number}}</strong>, Produkt: {{product_name}}, Garantieart: {{warranty_claim_type}}</p><p>{{defect_description}}</p>'),
    ('rma_ticket_status_update', 'en', 'Update on your defect report for order {{order_number}}',
     '<h2 style="margin-top:0;">Your RMA ticket status has changed</h2><p>Order <strong>{{order_number}}</strong> is now:</p><p style="font-size:18px;font-weight:bold;text-transform:uppercase;">{{status}}</p>{{resolution_notes}}'),
    ('rma_ticket_status_update', 'de', 'Update zu Ihrer Mängelmeldung für Bestellung {{order_number}}',
     '<h2 style="margin-top:0;">Ihr RMA-Ticket-Status hat sich geändert</h2><p>Bestellung <strong>{{order_number}}</strong> hat jetzt den Status:</p><p style="font-size:18px;font-weight:bold;text-transform:uppercase;">{{status}}</p>{{resolution_notes}}');

-- ------------------------------------------------------------
-- Contact form (Controllers\Storefront\ContactController). Rate-limited
-- via Services\RateLimiter bound to contact_message_attempts (same class,
-- shape, and purpose as login_attempts - see that table's comment).
-- ------------------------------------------------------------
CREATE TABLE contact_messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(190) NOT NULL,
    subject VARCHAR(200) NULL,
    message TEXT NOT NULL,
    order_number VARCHAR(32) NULL,
    customer_id INT UNSIGNED NULL,
    ip_address VARCHAR(45) NULL,
    status ENUM('new','read','replied','closed') NOT NULL DEFAULT 'new',
    admin_notes VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
    INDEX idx_contact_messages_status (status)
) ENGINE=InnoDB;

CREATE TABLE contact_message_attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identifier VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_contact_attempts_identifier (identifier, created_at)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Right of withdrawal (Widerrufsrecht) - functional self-service flow,
-- not just the disclosure page above. withdrawal_request_items lets a
-- request cover a subset of an order's items, since is_hygiene_product
-- items must be excludable individually (see products.is_hygiene_product).
-- Models\WithdrawalRequest extends the shared Models\CustomerRequest base
-- it has in common with Models\RmaTicket below.
-- ------------------------------------------------------------
CREATE TABLE withdrawal_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    -- Admin-configurable via Admin -> Numbering (Services\NumberSequenceService);
    -- NULL for any request submitted before that feature existed.
    withdrawal_number VARCHAR(50) NULL UNIQUE,
    order_id INT UNSIGNED NOT NULL,
    customer_id INT UNSIGNED NULL,
    reason TEXT NULL,
    status ENUM('submitted','under_review','approved','rejected','refunded','cancelled') NOT NULL DEFAULT 'submitted',
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Snapshotted at submission time (see Models\WithdrawalRequest::calculateDeadline())
    -- rather than recomputed later, so a later change to the
    -- withdrawal_period_days/assumed_delivery_days_after_shipment settings
    -- never retroactively moves an already-submitted request's deadline.
    deadline_at DATETIME NOT NULL,
    admin_notes VARCHAR(500) NULL,
    processed_by INT UNSIGNED NULL,
    processed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
    FOREIGN KEY (processed_by) REFERENCES admin_users(id) ON DELETE SET NULL,
    UNIQUE KEY uniq_withdrawal_order (order_id),
    INDEX idx_withdrawal_status (status)
) ENGINE=InnoDB;

CREATE TABLE withdrawal_request_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    withdrawal_request_id INT UNSIGNED NOT NULL,
    order_item_id INT UNSIGNED NOT NULL,
    FOREIGN KEY (withdrawal_request_id) REFERENCES withdrawal_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_withdrawal_item (withdrawal_request_id, order_item_id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- RMA / defect tickets - warranty-based returns, distinct from the no-
-- reason-needed withdrawal right above (different legal basis, different
-- (much longer) time window - see products.statutory_warranty_months/
-- manufacturer_warranty_months and Models\RmaTicket::isEligible()).
-- Item-level (order_item_id), not order-level, since a defect claim is
-- always about one specific product.
-- ------------------------------------------------------------
CREATE TABLE rma_tickets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    -- Admin-configurable via Admin -> Numbering (Services\NumberSequenceService);
    -- NULL for any ticket submitted before that feature existed.
    rma_number VARCHAR(50) NULL UNIQUE,
    order_id INT UNSIGNED NOT NULL,
    order_item_id INT UNSIGNED NOT NULL,
    customer_id INT UNSIGNED NULL,
    defect_description TEXT NOT NULL,
    warranty_claim_type ENUM('statutory','manufacturer') NOT NULL DEFAULT 'statutory',
    status ENUM('submitted','under_review','awaiting_return','approved','rejected','repaired','replaced','refunded','closed') NOT NULL DEFAULT 'submitted',
    resolution_notes VARCHAR(500) NULL,
    admin_notes VARCHAR(500) NULL,
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_by INT UNSIGNED NULL,
    processed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
    FOREIGN KEY (processed_by) REFERENCES admin_users(id) ON DELETE SET NULL,
    INDEX idx_rma_status (status)
) ENGINE=InnoDB;

-- Defect photo evidence - up to 5 per ticket (enforced in
-- Controllers\Storefront\RmaController), validated the same
-- extension-whitelist-plus-real-content-check way as
-- admin/product_images.php (see docs/SECURITY_AUDIT.md finding #6).
CREATE TABLE rma_ticket_attachments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rma_ticket_id INT UNSIGNED NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (rma_ticket_id) REFERENCES rma_tickets(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Legal documents (Admin -> Legal Documents) - either an uploaded PDF or
-- one generated on the fly from admin-typed text via
-- Services\PdfDocumentGenerator (built on the same SimplePdf writer
-- InvoiceGenerator already uses - no new dependency). `type` is a free
-- string key (not an ENUM) so a new document type never needs a schema
-- change - 'cancellation_policy' and 'warranty_terms' are just the two
-- the admin UI suggests out of the box.
-- ------------------------------------------------------------
CREATE TABLE legal_documents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(60) NOT NULL,
    language VARCHAR(5) NOT NULL DEFAULT 'en',
    title VARCHAR(200) NOT NULL,
    source_mode ENUM('uploaded','generated') NOT NULL,
    file_path VARCHAR(255) NULL,
    generated_text LONGTEXT NULL,
    generated_pdf_path VARCHAR(255) NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_legal_doc_type_lang (type, language)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Admin -> Numbering (Services\NumberSequenceService) - one row per
-- document type, all four seeded below since `type` is a fixed,
-- code-defined set (not admin-creatable) rather than an open list like
-- legal_documents.type above. Deliberately does NOT include order numbers
-- (orders.order_number) - those stay date+random on purpose, since a
-- sequential/guessable order number would reintroduce the brute-forceable
-- guest-order-lookup issue docs/SECURITY_AUDIT.md finding #4 fixed.
-- ------------------------------------------------------------
CREATE TABLE number_sequences (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(30) NOT NULL UNIQUE,
    prefix VARCHAR(20) NOT NULL DEFAULT '',
    suffix VARCHAR(20) NOT NULL DEFAULT '',
    -- Raw PHP date() format tokens (e.g. "Y", "Ym"); '' means no date
    -- component at all. Non-letter characters (e.g. "-") aren't reserved
    -- date() tokens, so an admin can bake a literal separator straight
    -- into this without any escaping - see Services\NumberSequenceService.
    date_format VARCHAR(20) NOT NULL DEFAULT '',
    padding TINYINT UNSIGNED NOT NULL DEFAULT 6,
    start_value INT UNSIGNED NOT NULL DEFAULT 1,
    increment INT UNSIGNED NOT NULL DEFAULT 1,
    -- The live counter - the next number that will actually be issued.
    -- Admin-editable (e.g. to skip ahead past legacy numbers), unlike
    -- start_value which only matters again on the next reset.
    next_value INT UNSIGNED NOT NULL DEFAULT 1,
    -- Only meaningful when date_format isn't '' - see
    -- Services\NumberSequenceService::next() for the reset logic.
    reset_on_date_change TINYINT(1) NOT NULL DEFAULT 0,
    -- The date_format output as of the last-issued number, used to detect
    -- a period rollover (e.g. year change) worth resetting on.
    current_period_key VARCHAR(20) NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO number_sequences (type, prefix, suffix, date_format, padding, start_value, increment, next_value) VALUES
    ('customer', 'C-', '', '', 6, 1, 1, 1),
    ('invoice', 'INV-', '', 'Y', 6, 1, 1, 1),
    ('rma_ticket', 'RMA-', '', '', 5, 1, 1, 1),
    ('withdrawal_request', 'WD-', '', '', 5, 1, 1, 1);
