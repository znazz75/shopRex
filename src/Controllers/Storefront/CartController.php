<?php

namespace ShopRex\Controllers\Storefront;

use ShopRex\Core\Container;
use ShopRex\Core\Controller;
use ShopRex\Core\Request;
use ShopRex\Core\Response;
use ShopRex\Models\Cart;

/**
 * Direct port of cart.php (display) + cart_action.php (mutations - add/
 * update/remove, dispatched by $_POST['action'], same single-endpoint
 * shape as the original so product/show.php's still-unrewritten-style
 * add-to-cart form and this controller's own update/remove forms all post
 * to the same place). Every mutation goes through Models\Cart's API only -
 * no $_SESSION['cart'] access here at all, closing the two encapsulation
 * breaks the original cart_action.php had (see Models\Cart's docblock).
 */
final class CartController extends Controller
{
    private readonly Cart $cart;
    private readonly \PDO $pdo;

    public function __construct(Request $request, Container $container)
    {
        parent::__construct($request, $container);
        $this->cart = $container->make(Cart::class);
        $this->pdo = $container->make(\PDO::class);
    }

    /** Ported from cart.php. */
    public function index(Request $request): Response
    {
        $cart = $this->cart->getItems();
        $items = $cart['items'];
        $subtotal = $cart['subtotal'];
        $tax = $cart['tax_total'];
        $taxBreakdown = $cart['tax_breakdown'];

        $cartWeightKg = $this->cart->getWeightKg();
        $totalQuantity = $this->cart->count();
        $shipping = null;
        $activeShippingMethods = $this->cart->getActiveShippingMethods();
        foreach ($activeShippingMethods as $method) {
            $cost = $this->cart->calculateShippingForMethod((int)$method['id'], $cartWeightKg, $subtotal, $totalQuantity);
            if ($shipping === null || $cost < $shipping) {
                $shipping = $cost;
            }
        }
        $shipping = $shipping ?? 0.0;
        $continueShoppingUrl = $request->session()->get('last_product_url', rtrim(SITE_URL, '/') . '/');
        $pageTitle = __('cart.title');

        return $this->render('cart/index', compact(
            'items', 'subtotal', 'tax', 'taxBreakdown', 'shipping',
            'activeShippingMethods', 'continueShoppingUrl', 'pageTitle'
        ));
    }

    /** Direct port of cart_action.php - single POST endpoint, action=add|update|remove. */
    public function handleAction(Request $request): Response
    {
        if (!$request->isPost()) {
            return $this->redirect('/cart');
        }
        if ($csrfFailure = $this->requireCsrf()) {
            return $csrfFailure;
        }

        $action = (string)$request->post('action', '');

        return match ($action) {
            'add'    => $this->add($request),
            'update' => $this->update($request),
            'remove' => $this->remove($request),
            default  => $this->redirect('/cart'),
        };
    }

    private function add(Request $request): Response
    {
        $productId = (int)$request->post('product_id', 0);
        $quantity = max(1, (int)$request->post('quantity', 1));
        $optionValueIds = [];

        $submittedOptions = $request->post('options');
        // SECURITY (docs/SECURITY_AUDIT.md finding #1): every submitted
        // option value must actually belong to *this* product's own
        // option groups - otherwise a crafted option_value_id borrowed
        // from a different product could later have its price_modifier/
        // stock_quantity applied here via Models\Cart::getItems().
        if (!empty($submittedOptions) && is_array($submittedOptions)) {
            foreach ($submittedOptions as $optId => $valId) {
                $valId = (int)$valId;
                if ($valId <= 0) {
                    $this->flash('error', __('cart.select_all_options'));
                    return $this->redirectBackOrHome($request);
                }
                $ownStmt = $this->pdo->prepare(
                    'SELECT 1 FROM product_option_values ov
                     JOIN product_options po ON po.id = ov.product_option_id
                     WHERE ov.id = ? AND po.product_id = ?'
                );
                $ownStmt->execute([$valId, $productId]);
                if (!$ownStmt->fetchColumn()) {
                    $this->flash('error', __('cart.select_all_options'));
                    return $this->redirectBackOrHome($request);
                }
                $optionValueIds[] = $valId;
            }
        }

        $stmt = $this->pdo->prepare("SELECT id, name, stock_quantity, max_order_quantity FROM products WHERE id = ? AND status = 'active'");
        $stmt->execute([$productId]);
        $product = $stmt->fetch();
        if (!$product) {
            $this->flash('error', __('cart.product_unavailable'));
            return $this->redirect('/');
        }

        $existingKey = $this->cart->key($productId, $optionValueIds);
        $existingQty = $this->cart->quantityFor($existingKey);

        if ($product['max_order_quantity'] !== null) {
            $maxOrderQty = (int)$product['max_order_quantity'];
            if ($existingQty + $quantity > $maxOrderQty) {
                $quantity = max(0, $maxOrderQty - $existingQty);
                $this->flash('error', __('cart.quantity_limited', ['name' => $product['name'], 'n' => $maxOrderQty]));
                if ($quantity <= 0) {
                    return $this->redirectBackOrHome($request);
                }
            }
        }

        if ($optionValueIds) {
            $variant = $this->cart->findVariant($productId, $optionValueIds);
            if ($variant) {
                $available = (int)$variant['stock_quantity'] - $existingQty;
                if ($quantity > $available) {
                    $quantity = max(0, $available);
                    $this->flash('error', $quantity > 0
                        ? __('cart.only_left', ['n' => $available])
                        : __('shop.out_of_stock'));
                    if ($quantity <= 0) {
                        return $this->redirectBackOrHome($request);
                    }
                }
            }
        }

        $this->cart->add($productId, $optionValueIds, $quantity);
        $this->flash('success', __('cart.added'));
        return $this->redirect('/cart');
    }

    private function update(Request $request): Response
    {
        $quantities = $request->post('quantity', []);
        $limitedNames = [];
        foreach ((array)$quantities as $key => $qty) {
            $qty = (int)$qty;
            if ($qty > 0 && $this->cart->has($key)) {
                // Need the line's product_id to re-check max_order_quantity -
                // Models\Cart doesn't expose raw lines, so read it back via
                // getItems()' already-hydrated product_id for this key.
                $productId = null;
                foreach ($this->cart->getItems()['items'] as $item) {
                    if ($item['key'] === $key) {
                        $productId = $item['product_id'];
                        break;
                    }
                }
                if ($productId !== null) {
                    $stmt = $this->pdo->prepare('SELECT name, max_order_quantity FROM products WHERE id = ?');
                    $stmt->execute([$productId]);
                    $p = $stmt->fetch();
                    if ($p && $p['max_order_quantity'] !== null && $qty > (int)$p['max_order_quantity']) {
                        $qty = (int)$p['max_order_quantity'];
                        $limitedNames[] = __('cart.quantity_limited', ['name' => $p['name'], 'n' => $p['max_order_quantity']]);
                    }
                }
            }
            $this->cart->updateQuantity((string)$key, $qty);
        }
        foreach ($limitedNames as $msg) {
            $this->flash('error', $msg);
        }
        $this->flash('success', __('cart.updated'));
        return $this->redirect('/cart');
    }

    private function remove(Request $request): Response
    {
        $this->cart->remove((string)$request->post('key', ''));
        $this->flash('success', __('cart.item_removed'));
        return $this->redirect('/cart');
    }

    private function redirectBackOrHome(Request $request): Response
    {
        $referer = $request->referer();
        return $referer ? Response::redirect($referer) : $this->redirect('/');
    }
}
