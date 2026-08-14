<?php

namespace ShopRex\Controllers\Storefront;

use ShopRex\Core\Auth\AdminAuth;
use ShopRex\Core\Auth\CustomerAuth;
use ShopRex\Core\Container;
use ShopRex\Core\Controller;
use ShopRex\Core\Request;
use ShopRex\Core\Response;
use ShopRex\Models\Order;

/** Direct port of order_confirmation.php + invoice_download.php. */
final class OrderController extends Controller
{
    private readonly \PDO $pdo;

    public function __construct(Request $request, Container $container)
    {
        parent::__construct($request, $container);
        $this->pdo = $container->make(\PDO::class);
    }

    public function confirmation(Request $request): Response
    {
        $orderNumber = (string)$request->routeParam('orderNumber', '');
        $order = Order::findByNumber($orderNumber);

        if (!$order) {
            $html = $this->view->render('order/not_found', ['pageTitle' => __('order.not_found_title')]);
            return Response::html($html, 404);
        }

        if (!$this->isAccessible($order, $request)) {
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
            ->withHeader('Content-Length', (string)strlen($pdfBytes))
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    private function isAccessible(Order $order, Request $request): bool
    {
        $customer = CustomerAuth::current();
        $isAdmin = AdminAuth::current() !== null;
        $lastOrderId = $request->session()->get('last_order_id');
        $isJustPlaced = $lastOrderId !== null && (int)$lastOrderId === (int)$order->id;

        return $order->isAccessibleBy($customer, $isAdmin, $isJustPlaced);
    }
}
