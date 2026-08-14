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

-- Optional per-language "intro text" shown above the product grid on the
-- storefront when this category (or one of its descendants, via
-- index.php?category=) is being browsed. Unlike categories.name/slug/description
-- (single-catalog, not translated - see README's i18n scope note), this one
-- field is deliberately structured per-language, the same (category_id, language)
-- pattern the `pages` table uses for (slug, language) - one row per language,
-- looked up with an English/default-language fallback (see getCategoryIntroText()
-- in includes/functions.php).
CREATE TABLE category_translations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id INT UNSIGNED NOT NULL,
    language VARCHAR(5) NOT NULL,
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
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    FOREIGN KEY (tax_rate_id) REFERENCES tax_rates(id) ON DELETE SET NULL,
    INDEX idx_products_status (status),
    INDEX idx_products_price (price)
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
    crop_x INT UNSIGNED NULL,
    crop_y INT UNSIGNED NULL,
    crop_width INT UNSIGNED NULL,
    crop_height INT UNSIGNED NULL,
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
    quantity INT NOT NULL DEFAULT 1,
    unit_price DECIMAL(10,2) NOT NULL,
    total_price DECIMAL(10,2) NOT NULL,
    tax_rate_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    tax_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
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
      Platzhalter durch Ihre eigene Urheberrechtserklärung.</p>', 1);

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
    ('main', 'Home', 'custom', 'index.php', 1),
    ('main', 'About Us', 'page', 'about-us', 2),
    ('footer', 'About Us', 'page', 'about-us', 1),
    ('footer', 'Legal Notice', 'page', 'legal-notice', 2),
    ('footer', 'Privacy Policy', 'page', 'privacy-policy', 3),
    ('footer', 'Copyright', 'page', 'copyright', 4);
