<?php

namespace ShopRex\Controllers\Storefront;

use ShopRex\Core\Auth\CustomerAuth;
use ShopRex\Core\Container;
use ShopRex\Core\Controller;
use ShopRex\Core\Request;
use ShopRex\Core\Response;
use ShopRex\Models\Cart;
use ShopRex\Services\CheckoutException;
use ShopRex\Services\CheckoutService;
use ShopRex\Services\SettingsRepository;

/** Direct port of checkout.php (display) + checkout_process.php (both branches). */
final class CheckoutController extends Controller
{
    private readonly Cart $cart;
    private readonly \PDO $pdo;
    private readonly CheckoutService $checkout;
    private readonly SettingsRepository $settings;

    public function __construct(Request $request, Container $container)
    {
        parent::__construct($request, $container);
        $this->cart = $container->make(Cart::class);
        $this->pdo = $container->make(\PDO::class);
        $this->checkout = $container->make(CheckoutService::class);
        $this->settings = $container->make(SettingsRepository::class);
    }

    /** Ported from checkout.php. */
    public function index(Request $request): Response
    {
        $cart = $this->cart->getItems();
        $items = $cart['items'];
        $subtotal = $cart['subtotal'];

        if (empty($items)) {
            $this->flash('error', __('cart.empty'));
            return $this->redirect('/cart');
        }

        $cartWeightKg = $this->cart->getWeightKg();
        $totalQuantity = $this->cart->count();
        $shippingMethods = $this->cart->getActiveShippingMethods();
        foreach ($shippingMethods as &$method) {
            $method['cost'] = $this->cart->calculateShippingForMethod((int)$method['id'], $cartWeightKg, $subtotal, $totalQuantity);
        }
        unset($method);

        $selectedShippingMethodId = (int)$request->post('shipping_method_id', $shippingMethods[0]['id'] ?? 0);
        $shipping = 0.0;
        foreach ($shippingMethods as $method) {
            if ((int)$method['id'] === $selectedShippingMethodId) {
                $shipping = $method['cost'];
                break;
            }
        }

        $tax = $cart['tax_total'];
        $taxBreakdown = $cart['tax_breakdown'];
        $total = $subtotal + $shipping + $tax;

        $customer = CustomerAuth::current();

        $availablePaymentMethods = [];
        if ($this->settings->isPaymentMethodEnabled('bank_transfer')) {
            $availablePaymentMethods[] = 'bank_transfer';
        }
        if ($this->settings->isPaymentMethodEnabled('paypal')) {
            $availablePaymentMethods[] = 'paypal';
        }
        if ($this->settings->isPaymentMethodEnabled('credit_card')) {
            $availablePaymentMethods[] = 'credit_card';
        }
        if ($this->settings->customerCanPayOnInvoice($customer)) {
            $availablePaymentMethods[] = 'invoice';
        }

        $pageTitle = __('checkout.title');
        $cancelled = (bool)$request->get('cancelled');

        return $this->render('checkout/index', compact(
            'items', 'subtotal', 'shipping', 'tax', 'taxBreakdown', 'total',
            'shippingMethods', 'selectedShippingMethodId', 'customer',
            'availablePaymentMethods', 'pageTitle', 'cancelled'
        ));
    }

    /** POST /checkout_process.php - place the order. Ported from checkout_process.php:16-223. */
    public function process(Request $request): Response
    {
        if ($csrfFailure = $this->requireCsrf()) {
            return $csrfFailure;
        }

        $customer = CustomerAuth::current();

        try {
            $result = $this->checkout->placeOrder($request->all(), $customer);
        } catch (CheckoutException $e) {
            $this->flash('error', $e->getMessage());
            return $this->redirect($e->redirectPath);
        }

        // Remember that *this* browser session is the one that just placed
        // this order - OrderController uses it to show a guest their own
        // order without requiring an account (see Models\Order::isAccessibleBy()).
        $this->request->session()->set('last_order_id', $result->order->id);

        if ($result->gatewayRedirectUrl) {
            return Response::redirect($result->gatewayRedirectUrl);
        }
        return $this->redirect('/order/' . urlencode($result->order->orderNumber));
    }

    /** GET /checkout/capture - gateway return URL. Ported from checkout_process.php:8-11 + handleCapture(). */
    public function capture(Request $request): Response
    {
        $gateway = (string)$request->get('gateway', '');
        $orderNumber = (string)$request->get('order', '');
        $sessionId = $request->get('session_id');

        try {
            $order = $this->checkout->handleCapture($gateway, $orderNumber, $request->get('token'), $sessionId);
        } catch (\RuntimeException $e) {
            return Response::html('Order not found.', 404);
        }

        return $this->redirect('/order/' . urlencode($order->orderNumber));
    }
}
