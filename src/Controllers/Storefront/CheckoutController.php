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

/**
 * Drives the checkout flow: showing the checkout page (shipping/payment
 * choice + order summary), placing the order, and handling the browser's
 * return from an external payment gateway (PayPal/Stripe). This last
 * piece - capture() below - is one of the highest-security-sensitivity
 * entry points in the app; see its own docblock and
 * docs/SECURITY_AUDIT.md finding #2 before changing anything here.
 * Direct port of checkout.php (display) + checkout_process.php (both branches).
 */
final class CheckoutController extends Controller
{
    private readonly Cart $cart; // The current visitor's shopping cart - re-read here to compute totals/shipping options and, in process(), to hand off to CheckoutService for order creation.
    private readonly \PDO $pdo; // Raw DB handle (currently unused directly in this controller's own queries, but kept available for the container-provided pattern shared with sibling controllers).
    private readonly CheckoutService $checkout; // Where the actual "create the order, charge/redirect to the gateway, verify a capture" logic lives - this controller is a thin HTTP-facing wrapper around it.
    private readonly SettingsRepository $settings; // Used here to check which payment methods are currently enabled for display.

    public function __construct(Request $request, Container $container)
    {
        parent::__construct($request, $container);
        $this->cart = $container->make(Cart::class);
        $this->pdo = $container->make(\PDO::class);
        $this->checkout = $container->make(CheckoutService::class);
        $this->settings = $container->make(SettingsRepository::class);
    }

    /**
     * Shows the checkout page: cart contents, shipping cost for the
     * selected (or default) shipping method, tax breakdown, and which
     * payment methods are actually available to this customer. Redirects
     * back to the cart if it's empty, since there's nothing to check out.
     * Ported from checkout.php.
     */
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
        // Each shipping method's cost can depend on cart weight/subtotal/
        // quantity (e.g. free shipping over a threshold, per-kg rates), so
        // it's computed per method here rather than being a flat stored price.
        $shippingMethods = $this->cart->getActiveShippingMethods();
        foreach ($shippingMethods as &$method) {
            $method['cost'] = $this->cart->calculateShippingForMethod((int)$method['id'], $cartWeightKg, $subtotal, $totalQuantity);
        }
        unset($method);

        // Whatever the shopper had selected (re-posted when they change
        // shipping method on this page) falls back to the first available
        // method if nothing was posted yet.
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

        // Only offer payment methods the shop has actually enabled in
        // Settings - 'invoice' additionally requires this specific
        // customer's account to have that permission (see
        // SettingsRepository::customerCanPayOnInvoice()), so it's not a
        // simple on/off setting like the other three.
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

    /**
     * Places the order: hands the submitted form data (shipping address,
     * chosen shipping/payment method, etc.) and the current customer (or
     * null for a guest) to CheckoutService::placeOrder(), which does the
     * actual validation, re-pricing from the server-side cart, and
     * creation. All of the "is this actually valid/affordable" logic lives
     * in that service, not here - this method's job is purely translating
     * the HTTP request into that call and the result into a redirect.
     * POST /checkout_process.php - place the order. Ported from
     * checkout_process.php:16-223.
     */
    public function process(Request $request): Response
    {
        if ($csrfFailure = $this->requireCsrf()) {
            return $csrfFailure;
        }

        $customer = CustomerAuth::current();

        try {
            $result = $this->checkout->placeOrder($request->all(), $customer);
        } catch (CheckoutException $e) {
            // CheckoutException carries both a user-facing message (e.g.
            // "that item just sold out") and where to send the shopper back
            // to - lets CheckoutService reject an order for many different
            // reasons without this controller needing to know each one.
            $this->flash('error', $e->getMessage());
            return $this->redirect($e->redirectPath);
        }

        // Remember that *this* browser session is the one that just placed
        // this order - OrderController uses it to show a guest their own
        // order without requiring an account (see Models\Order::isAccessibleBy()).
        $this->request->session()->set('last_order_id', $result->order->id);

        // A redirect-based gateway (PayPal/Stripe) sends the shopper off-site
        // to approve payment before landing back on capture() below; the
        // non-redirect methods (bank transfer/invoice/test) go straight to
        // the order confirmation page.
        if ($result->gatewayRedirectUrl) {
            return Response::redirect($result->gatewayRedirectUrl);
        }
        return $this->redirect('/order/' . urlencode($result->order->orderNumber));
    }

    /**
     * The PayPal/Stripe "return URL" the shopper's browser lands on after
     * approving (or cancelling) payment on the gateway's own site - reached
     * via a plain GET, so nothing about this request (including the order
     * number and gateway-supplied token/session_id in the query string) can
     * be trusted as-is; it's all attacker-visible/attacker-modifiable input.
     *
     * SECURITY (docs/SECURITY_AUDIT.md finding #2): this method itself does
     * no verification - it just passes $gateway/$orderNumber/token/
     * $sessionId straight through to
     * Services\CheckoutService::handleCapture(), which is what actually
     * enforces that only the identifier stored on THIS order's own
     * `payments` row (never the query string's token/session_id directly)
     * is used to ask the gateway "was this really paid, and for the right
     * amount?" before marking anything paid. See
     * Payment\PayPalGateway::capture() / Payment\CreditCardGateway::capture()
     * for exactly how that binding works and what attack it prevents.
     * GET /checkout/capture - gateway return URL. Ported from
     * checkout_process.php:8-11 + handleCapture().
     */
    public function capture(Request $request): Response
    {
        $gateway = (string)$request->get('gateway', '');
        $orderNumber = (string)$request->get('order', '');
        $sessionId = $request->get('session_id');

        try {
            $order = $this->checkout->handleCapture($gateway, $orderNumber, $request->get('token'), $sessionId);
        } catch (\RuntimeException $e) {
            // Order number in the URL doesn't resolve to a real order at
            // all - nothing to capture against.
            return Response::html('Order not found.', 404);
        }

        // Whether or not the capture actually succeeded, send the shopper
        // to the normal order confirmation page - it displays whatever the
        // order's real, server-verified payment_status ended up being
        // (handleCapture() only marks it paid when verification passed),
        // rather than this response claiming success/failure itself.
        return $this->redirect('/order/' . urlencode($order->orderNumber));
    }
}
