<?php

namespace ShopRex\Controllers\Storefront;

use ShopRex\Core\Auth\AdminAuth;
use ShopRex\Core\Auth\CustomerAuth;
use ShopRex\Core\Container;
use ShopRex\Core\Controller;
use ShopRex\Core\Request;
use ShopRex\Core\Response;
use ShopRex\Models\Order;

/**
 * Order confirmation page and invoice PDF download - both are pages that
 * disclose a customer's personal order details, so both are access-
 * controlled (see isAccessible() and downloadInvoice() below). This closes
 * docs/SECURITY_AUDIT.md finding #4, where the original
 * order_confirmation.php had NO access control at all - anyone who could
 * see or guess an order number (and order numbers were shown to be
 * brute-forceable) could view another customer's full name, address,
 * email, and purchase details. Direct port of order_confirmation.php +
 * invoice_download.php.
 */
final class OrderController extends Controller
{
    private readonly \PDO $pdo; // Raw DB handle for the invoice-existence/invoice-row lookups below.

    public function __construct(Request $request, Container $container)
    {
        parent::__construct($request, $container);
        $this->pdo = $container->make(\PDO::class);
    }

    /**
     * Shows the "thank you for your order" confirmation page. Access is
     * gated by isAccessible() below (order owner, an admin, or the guest
     * who just placed it) rather than the order number alone being
     * sufficient - see this class's docblock for why.
     */
    public function confirmation(Request $request): Response
    {
        $orderNumber = (string)$request->routeParam('orderNumber', '');
        $order = Order::findByNumber($orderNumber);

        if (!$order) {
            $html = $this->view->render('order/not_found', ['pageTitle' => __('order.not_found_title')]);
            return Response::html($html, 404);
        }

        if (!$this->isAccessible($order, $request)) {
            // Same "not found" response/status intentionally reused for
            // "exists but you can't see it" (403) as for "doesn't exist"
            // (404 above) - avoids confirming to an unauthorized visitor
            // that a given order number is real.
            $html = $this->view->render('order/not_found', ['pageTitle' => __('order.not_found_title')]);
            return Response::html($html, 403);
        }

        $items = $order->items();

        $invoiceStmt = $this->pdo->prepare('SELECT * FROM invoices WHERE order_id = ? LIMIT 1');
        $invoiceExists = false;
        try {
            $invoiceStmt->execute([$order->id]);
            $invoiceExists = (bool)$invoiceStmt->fetch();
        } catch (\Throwable $e) {
            // invoices table not present yet (e.g. pre-migration DB) - just hide the link.
        }

        $pageTitle = __('order.confirmation_title');

        return $this->render('order/confirmation', compact('order', 'items', 'invoiceExists', 'pageTitle'));
    }

    /**
     * Streams the order's invoice PDF, gated by ownership or admin status
     * only - unlike confirmation() above, there's no "just placed this
     * order" allowance here, since a guest who just checked out doesn't
     * have an invoice generated yet at that point in the flow anyway.
     */
    public function downloadInvoice(Request $request): Response
    {
        $orderNumber = (string)$request->routeParam('orderNumber', '');
        $order = Order::findByNumber($orderNumber);

        if (!$order) {
            return Response::html('Order not found.', 404);
        }

        $customer = CustomerAuth::current();
        $isOwner = $customer && $order->customerId !== null && (int)$order->customerId === (int)$customer['id'];
        $isAdmin = AdminAuth::current() !== null;

        // Access control: only the order's own logged-in customer or any
        // admin may download this invoice - without this check, anyone who
        // knew/guessed the order number could pull another customer's
        // invoice (name, address, itemized purchase, price paid).
        if (!$isOwner && !$isAdmin) {
            return Response::html('You do not have permission to view this invoice.', 403);
        }

        $invStmt = $this->pdo->prepare('SELECT * FROM invoices WHERE order_id = ? LIMIT 1');
        $invStmt->execute([$order->id]);
        $invoice = $invStmt->fetch();

        if (!$invoice || !is_file($invoice['pdf_path'])) {
            return Response::html('Invoice not available for this order yet.', 404);
        }

        $pdfBytes = (string)file_get_contents($invoice['pdf_path']);
        return Response::html($pdfBytes)
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'inline; filename="' . basename($invoice['invoice_number']) . '.pdf"')
            // X-Content-Type-Options: nosniff stops the browser from
            // second-guessing the declared Content-Type based on sniffed
            // file content.
            ->withHeader('Content-Length', (string)strlen($pdfBytes))
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    /**
     * The three ways a visitor is allowed to view this order's
     * confirmation page: they own it (logged-in customer whose id matches),
     * they're an admin, or this is the guest who just placed it this same
     * browser session (see CheckoutController::process(), which sets
     * 'last_order_id' right after order creation) - that third case is what
     * lets a guest with no account see their own just-placed order without
     * requiring login. Delegates the actual decision to
     * Order::isAccessibleBy() so the rule lives on the model, not duplicated
     * across controllers.
     */
    private function isAccessible(Order $order, Request $request): bool
    {
        $customer = CustomerAuth::current();
        $isAdmin = AdminAuth::current() !== null;
        $lastOrderId = $request->session()->get('last_order_id');
        $isJustPlaced = $lastOrderId !== null && (int)$lastOrderId === (int)$order->id;

        return $order->isAccessibleBy($customer, $isAdmin, $isJustPlaced);
    }
}
