-- ============================================================
-- shopRex - optional demo content (sample categories/products)
-- Offered as a checkbox during install; safe to skip on a real shop.
-- Import manually with:  mysql -u root -p your_db_name < sql/seed_demo.sql
-- ============================================================

-- Categories, 3 levels deep, to demonstrate unlimited subcategory nesting:
--   Apparel
--     Apparel > Men
--       Apparel > Men > T-Shirts
--     Apparel > Women
--   Accessories
INSERT INTO categories (id, parent_id, name, slug, description) VALUES
    (1, NULL, 'Apparel', 'apparel', 'Shirts, hoodies and more'),
    (2, 1,    'Men', 'apparel-men', 'Menswear'),
    (3, 2,    'T-Shirts', 'apparel-men-t-shirts', 'Men''s t-shirts'),
    (4, 1,    'Women', 'apparel-women', 'Womenswear'),
    (5, NULL, 'Accessories', 'accessories', 'Bags, caps and other accessories');

-- Product 2 carries a permanent 20% discount (no date range - always on).
-- Product 3 carries a time-limited discount (starts now, ends in 14 days)
-- to demonstrate the "clearly show the date range" requirement.
-- All three use tax_rate_id 1 ("Standard", 19% - see schema.sql's tax_rates seed).
INSERT INTO products (id, category_id, sku, name, slug, short_description, description, price, tax_rate_id, discount_type, discount_value, discount_starts_at, discount_ends_at, stock_quantity, status) VALUES
    (1, 3, 'TSHIRT-001', 'Classic T-Shirt', 'classic-t-shirt', 'Soft cotton t-shirt for everyday wear.', 'A classic fit t-shirt made from 100% combed cotton. Available in several sizes and colors.', 2.49, 1, 'none', NULL, NULL, NULL, 50, 'active'),
    (2, 1, 'HOODIE-001', 'Comfy Hoodie', 'comfy-hoodie', 'Warm fleece hoodie with front pocket.', 'Stay warm with this heavyweight fleece hoodie featuring a kangaroo pocket and adjustable drawstring hood.', 2.90, 1, 'percent', 20.00, NULL, NULL, 30, 'active'),
    (3, 5, 'CAP-001', 'Baseball Cap', 'baseball-cap', 'Adjustable cotton baseball cap.', 'One-size-fits-all adjustable baseball cap with embroidered logo.', 1.90, 1, 'fixed', 0.50, NOW(), DATE_ADD(NOW(), INTERVAL 14 DAY), 100, 'active');

INSERT INTO product_options (id, product_id, name, sort_order) VALUES
    (1, 1, 'Size', 1),
    (2, 1, 'Color', 2),
    (3, 2, 'Size', 1);

INSERT INTO product_option_values (product_option_id, value, price_modifier, stock_quantity, sort_order) VALUES
    (1, 'S', 0.00, 15, 1),
    (1, 'M', 0.00, 20, 2),
    (1, 'L', 0.00, 15, 3),
    (2, 'White', 0.00, 25, 1),
    (2, 'Black', 0.00, 25, 2),
    (3, 'S', 0.00, 10, 1),
    (3, 'M', 0.00, 10, 2),
    (3, 'L', 0.00, 10, 3);

-- Variant-level stock (one row per exact option combination) - this is
-- what actually gets checked/decremented at checkout (see
-- includes/Cart.php findVariant() / checkout_process.php); the
-- product_option_values.stock_quantity above is legacy/unused for stock
-- once these exist (see schema.sql's product_variants comment). Relies on
-- product_option_values auto-assigning ids 1-8 in the exact insertion
-- order above (S=1, M=2, L=3, White=4, Black=5, then product 2's S=6, M=7, L=8).
INSERT INTO product_variants (product_id, stock_quantity, sort_order) VALUES
    (1, 8, 1),  -- #1 T-Shirt S / White
    (1, 7, 2),  -- #2 T-Shirt S / Black
    (1, 10, 3), -- #3 T-Shirt M / White
    (1, 9, 4),  -- #4 T-Shirt M / Black
    (1, 6, 5),  -- #5 T-Shirt L / White
    (1, 6, 6),  -- #6 T-Shirt L / Black
    (2, 10, 1), -- #7 Hoodie S
    (2, 10, 2), -- #8 Hoodie M
    (2, 8, 3);  -- #9 Hoodie L

INSERT INTO product_variant_values (product_variant_id, product_option_value_id) VALUES
    (1, 1), (1, 4),
    (2, 1), (2, 5),
    (3, 2), (3, 4),
    (4, 2), (4, 5),
    (5, 3), (5, 4),
    (6, 3), (6, 5),
    (7, 6),
    (8, 7),
    (9, 8);

-- Add the demo categories to the main nav so browsing works out of the box.
INSERT INTO menu_items (location, label, link_type, link_value, sort_order) VALUES
    ('main', 'Apparel', 'category', '1', 3),
    ('main', 'Accessories', 'category', '5', 4);
