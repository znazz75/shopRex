<?php
require_once __DIR__ . '/includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(rtrim(SITE_URL, '/') . '/cart.php');
}
requireCsrf();

$action = $_POST['action'] ?? '';

switch ($action) {
    case 'add':
        $productId = (int)($_POST['product_id'] ?? 0);
        $quantity = max(1, (int)($_POST['quantity'] ?? 1));
        $optionValueIds = [];

        // Every option group (e.g. Size, Color) must have a value chosen, and
        // every chosen value must actually belong to one of *this* product's
        // own option groups - otherwise a crafted option_value_id borrowed
        // from a different product could later be used to apply its
        // price_modifier/stock_quantity to this one (see Cart::getItems(),
        // and docs/SECURITY_AUDIT.md finding #1).
        if (!empty($_POST['options']) && is_array($_POST['options'])) {
            foreach ($_POST['options'] as $optId => $valId) {
                $valId = (int)$valId;
                if ($valId <= 0) {
                    setFlash('error', __('cart.select_all_options'));
                    redirect($_SERVER['HTTP_REFERER'] ?? (rtrim(SITE_URL, '/') . '/index.php'));
                }
                $ownStmt = db()->prepare(
                    'SELECT 1 FROM product_option_values ov
                     JOIN product_options po ON po.id = ov.product_option_id
                     WHERE ov.id = ? AND po.product_id = ?'
                );
                $ownStmt->execute([$valId, $productId]);
                if (!$ownStmt->fetchColumn()) {
                    setFlash('error', __('cart.select_all_options'));
                    redirect($_SERVER['HTTP_REFERER'] ?? (rtrim(SITE_URL, '/') . '/index.php'));
                }
                $optionValueIds[] = $valId;
            }
        }

        $stmt = db()->prepare("SELECT id, name, stock_quantity, max_order_quantity FROM products WHERE id = ? AND status = 'active'");
        $stmt->execute([$productId]);
        $product = $stmt->fetch();
        if (!$product) {
            setFlash('error', __('cart.product_unavailable'));
            redirect(rtrim(SITE_URL, '/') . '/index.php');
        }

        $existingKey = Cart::key($productId, $optionValueIds);
        $existingQty = (int)($_SESSION['cart'][$existingKey]['quantity'] ?? 0);

        // Optional per-product cap (Admin -> Products -> edit) - counts
        // against whatever of this exact product+option combination is
        // already in the cart, not just this one "add" request.
        if ($product['max_order_quantity'] !== null) {
            $maxOrderQty = (int)$product['max_order_quantity'];
            if ($existingQty + $quantity > $maxOrderQty) {
                $quantity = max(0, $maxOrderQty - $existingQty);
                setFlash('error', __('cart.quantity_limited', ['name' => $product['name'], 'n' => $maxOrderQty]));
                if ($quantity <= 0) {
                    redirect($_SERVER['HTTP_REFERER'] ?? (rtrim(SITE_URL, '/') . '/index.php'));
                }
            }
        }

        // Combination-level stock check (Cart::findVariant() - see
        // includes/Cart.php) - skip for option-less products, whose stock
        // is only actually enforced at checkout_process.php time.
        if ($optionValueIds) {
            $variant = Cart::findVariant($productId, $optionValueIds);
            if ($variant) {
                $available = (int)$variant['stock_quantity'] - $existingQty;
                if ($quantity > $available) {
                    $quantity = max(0, $available);
                    setFlash('error', $quantity > 0
                        ? __('cart.only_left', ['n' => $available])
                        : __('shop.out_of_stock'));
                    if ($quantity <= 0) {
                        redirect($_SERVER['HTTP_REFERER'] ?? (rtrim(SITE_URL, '/') . '/index.php'));
                    }
                }
            }
        }

        Cart::add($productId, $optionValueIds, $quantity);
        setFlash('success', __('cart.added'));
        redirect(rtrim(SITE_URL, '/') . '/cart.php');
        break;

    case 'update':
        $limitedNames = [];
        foreach ($_POST['quantity'] ?? [] as $key => $qty) {
            $qty = (int)$qty;
            if ($qty > 0 && isset($_SESSION['cart'][$key])) {
                $stmt = db()->prepare('SELECT name, max_order_quantity FROM products WHERE id = ?');
                $stmt->execute([(int)$_SESSION['cart'][$key]['product_id']]);
                $p = $stmt->fetch();
                if ($p && $p['max_order_quantity'] !== null && $qty > (int)$p['max_order_quantity']) {
                    $qty = (int)$p['max_order_quantity'];
                    $limitedNames[] = __('cart.quantity_limited', ['name' => $p['name'], 'n' => $p['max_order_quantity']]);
                }
            }
            Cart::updateQuantity($key, $qty);
        }
        foreach ($limitedNames as $msg) {
            setFlash('error', $msg);
        }
        setFlash('success', __('cart.updated'));
        redirect(rtrim(SITE_URL, '/') . '/cart.php');
        break;

    case 'remove':
        Cart::remove($_POST['key'] ?? '');
        setFlash('success', __('cart.item_removed'));
        redirect(rtrim(SITE_URL, '/') . '/cart.php');
        break;

    default:
        redirect(rtrim(SITE_URL, '/') . '/cart.php');
}
