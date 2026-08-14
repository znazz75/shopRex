<?php

namespace ShopRex\Services;

use ShopRex\Models\Cart;
use ShopRex\Models\Order;
use ShopRex\Payment\CapturableGateway;
use ShopRex\Payment\PaymentGatewayFactory;
use ShopRex\Payment\TestGateway;

/**
 * Direct, line-cited port of checkout_process.php's two responsibilities:
 * placeOrder() is the POST branch (lines 16-223 of the original file),
 * handleCapture() is the GET ?action=capture branch (lines 8-11, 288-330).
 * Every security-relevant comment from the original is preserved verbatim
 * at its exact call site below, not paraphrased - this is the highest-risk
 * phase of the whole rewrite (see docs/SECURITY_AUDIT.md findings #2/#3
 * and the architecture plan's risk list), so nothing here is "improved"
 * during the port, only relocated.
 */
final class CheckoutService
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly Cart $cart,
        private readonly PaymentGatewayFactory $gateways,
        private readonly SettingsRepository $settings,
    ) {
    }

    /**
     * Ported from checkout_process.php:16-223.
     * @throws CheckoutException on any validation failure (mirrors the
     *         original's setFlash()+redirect() early exits).
     */
    public function placeOrder(array $post, ?array $customer): PlaceOrderResult
    {
        $cart = $this->cart->getItems();
        $items = $cart['items'];
        if (empty($items)) {
            throw new CheckoutException('Your cart is empty.', '/cart');
        }

        $email = filter_var($post['email'] ?? '', FILTER_VALIDATE_EMAIL);
        $paymentMethod = $post['payment_method'] ?? '';
        // Re-validate the payment method server-side rather than trusting
        // the submitted value - paypal/credit_card/bank_transfer must
        // currently be enabled (Admin -> Settings -> Payment Methods), and
        // invoice must have been explicitly granted to this specific
        // logged-in customer (Admin -> Customers -> [customer] -> Payment).
        $paymentMethodAllowed = match ($paymentMethod) {
            'paypal', 'credit_card', 'bank_transfer' => $this->settings->isPaymentMethodEnabled($paymentMethod),
            'invoice' => $this->settings->customerCanPayOnInvoice($customer),
            default => false,
        };
        if (!$email || !$paymentMethodAllowed) {
            throw new CheckoutException('Please fill in all required fields.');
        }

        $shippingName = trim($post['shipping_name'] ?? '');
        $shippingAddress1 = trim($post['shipping_address1'] ?? '');
        $shippingAddress2 = trim($post['shipping_address2'] ?? '');
        $shippingCity = trim($post['shipping_city'] ?? '');
        $shippingPostal = trim($post['shipping_postal_code'] ?? '');
        $shippingCountry = trim($post['shipping_country'] ?? '');
        $customerNotes = trim($post['customer_notes'] ?? '');

        if ($shippingName === '' || $shippingAddress1 === '' || $shippingCity === '' || $shippingPostal === '' || $shippingCountry === '') {
            throw new CheckoutException('Please complete the shipping address.');
        }

        $subtotal = $cart['subtotal'];

        // Shipping cost/method is always recomputed server-side from the
        // posted method id - never trust a client-submitted price.
        $shippingMethodId = (int)($post['shipping_method_id'] ?? 0);
        $cartWeightKg = $this->cart->getWeightKg();
        $totalQuantity = $this->cart->count();
        $shippingMethodName = null;
        $shippingCost = 0.0;
        if ($shippingMethodId) {
            $methodStmt = $this->pdo->prepare('SELECT name FROM shipping_methods WHERE id = ? AND is_active = 1');
            $methodStmt->execute([$shippingMethodId]);
            $shippingMethodName = $methodStmt->fetchColumn() ?: null;
            if ($shippingMethodName === null) {
                $shippingMethodId = null;
            } else {
                $shippingCost = $this->cart->calculateShippingForMethod($shippingMethodId, $cartWeightKg, $subtotal, $totalQuantity);
            }
        } else {
            $shippingMethodId = null;
        }

        $tax = $cart['tax_total'];
        $total = $subtotal + $shippingCost + $tax;

        // Trial orders placed by an Admin -> Customers -> Test Users
        // account: no real gateway call, no stock decrement, excluded from
        // financial reports.
        $isTestOrder = $customer && !empty($customer['is_test_account']);

        try {
            $this->pdo->beginTransaction();

            // Re-check stock for every line before committing to the order.
            foreach ($items as $item) {
                if ($item['quantity'] > $item['available_stock']) {
                    throw new \RuntimeException('"' . $item['name'] . '" only has ' . $item['available_stock'] . ' left in stock.');
                }
            }

            $orderNumber = $this->generateOrderNumber();

            $stmt = $this->pdo->prepare(
                'INSERT INTO orders (order_number, customer_id, guest_email, status, payment_method, payment_status, is_test_order, language,
                                      subtotal, shipping_cost, shipping_method_id, shipping_method_name, tax_total, total,
                                      shipping_name, shipping_address1, shipping_address2, shipping_city, shipping_postal_code, shipping_country,
                                      billing_same_as_shipping, customer_notes)
                 VALUES (?, ?, ?, "pending", ?, "pending", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)'
            );
            $stmt->execute([
                $orderNumber, $customer['id'] ?? null, $email, $paymentMethod, $isTestOrder ? 1 : 0, I18n::current(),
                $subtotal, $shippingCost, $shippingMethodId, $shippingMethodName, $tax, $total,
                $shippingName, $shippingAddress1, $shippingAddress2, $shippingCity, $shippingPostal, $shippingCountry,
                $customerNotes,
            ]);
            $orderId = (int)$this->pdo->lastInsertId();

            $itemStmt = $this->pdo->prepare(
                'INSERT INTO order_items (order_id, product_id, product_name, option_summary, quantity, unit_price, total_price, tax_rate_percent, tax_amount)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stockStmt = $this->pdo->prepare('UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ?');
            $variantStockStmt = $this->pdo->prepare('UPDATE product_variants SET stock_quantity = stock_quantity - ? WHERE id = ?');
            // Legacy fallback only (see Models\Cart::findVariant()'s fallback path).
            $optStockStmt = $this->pdo->prepare('UPDATE product_option_values SET stock_quantity = stock_quantity - ? WHERE id = ?');
            $logStmt = $this->pdo->prepare(
                'INSERT INTO inventory_log (product_id, option_value_id, product_variant_id, change_qty, reason, reference, is_test) VALUES (?, ?, ?, ?, "sale", ?, ?)'
            );

            foreach ($items as $item) {
                $itemStmt->execute([
                    $orderId, $item['product_id'], $item['name'], $item['option_label'],
                    $item['quantity'], $item['unit_price'], $item['line_total'],
                    $item['tax_rate'], $item['tax_amount'],
                ]);

                // Test orders are still written to the inventory log (so
                // the trial run is visible/auditable) but never actually
                // decrement stock.
                if (empty($item['option_value_ids'])) {
                    if (!$isTestOrder) {
                        $stockStmt->execute([$item['quantity'], $item['product_id']]);
                    }
                    $logStmt->execute([$item['product_id'], null, null, -$item['quantity'], $orderNumber, $isTestOrder ? 1 : 0]);
                } elseif ($item['variant_id']) {
                    if (!$isTestOrder) {
                        $variantStockStmt->execute([$item['quantity'], $item['variant_id']]);
                    }
                    $logStmt->execute([$item['product_id'], null, $item['variant_id'], -$item['quantity'], $orderNumber, $isTestOrder ? 1 : 0]);
                } else {
                    foreach ($item['option_value_ids'] as $optionValueId) {
                        if (!$isTestOrder) {
                            $optStockStmt->execute([$item['quantity'], $optionValueId]);
                        }
                        $logStmt->execute([$item['product_id'], $optionValueId, null, -$item['quantity'], $orderNumber, $isTestOrder ? 1 : 0]);
                    }
                }
            }

            $paymentStmt = $this->pdo->prepare(
                'INSERT INTO payments (order_id, payment_method, amount, currency, status) VALUES (?, ?, ?, ?, "pending")'
            );
            $paymentStmt->execute([$orderId, $paymentMethod, $total, $this->settings->get('currency', 'EUR')]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw new CheckoutException('Could not place order: ' . $e->getMessage(), '/cart');
        }

        $order = Order::findByNumber($orderNumber);

        // Order + items are committed. Now kick off the payment gateway (or,
        // for a test order, the no-op TestGateway that never calls out
        // anywhere).
        $orderItems = $order->items();
        $gateway = $isTestOrder ? new TestGateway() : $this->gateways->make($paymentMethod);
        $result = $gateway->start($order->toRow(), $orderItems);

        if (!empty($result['transaction_id'])) {
            $this->pdo->prepare('UPDATE payments SET transaction_id = ? WHERE order_id = ?')->execute([$result['transaction_id'], $orderId]);
        }

        if (($result['status'] ?? '') === 'completed') {
            $order->markPaid(
                $result['transaction_id'] ?? null,
                $isTestOrder ? 'Test order - simulated payment, no real transaction was processed.' : 'Captured immediately.',
                $this->pdo
            );
            $order = Order::findByNumber($orderNumber);
        }

        // Generate the invoice PDF (in the order's language) before emailing
        // the confirmation, so it can go out as an attachment.
        try {
            \InvoiceGenerator::generateForOrder($order->toRow(), $orderItems);
        } catch (\Throwable $e) {
            error_log('Invoice generation failed for order ' . $orderNumber . ': ' . $e->getMessage());
        }

        // Bank transfer (and any gateway that could not be reached) stays
        // "pending" and the customer is sent straight to the confirmation
        // page with instructions.
        $this->cart->clear();
        \Mailer::sendOrderConfirmation($order->toRow(), $orderItems);

        return new PlaceOrderResult($order, $result['redirect_url'] ?? null);
    }

    /**
     * Ported from checkout_process.php:288-330 (handleCapture) - the
     * per-gateway token/session-id binding check itself now lives inside
     * each CapturableGateway::capture() implementation (relocated, not
     * rewritten - see Payment\PayPalGateway/CreditCardGateway).
     */
    public function handleCapture(string $gatewayName, string $orderNumber, ?string $submittedToken, ?string $submittedSessionId): Order
    {
        $order = Order::findByNumber($orderNumber);
        if (!$order) {
            throw new \RuntimeException('Order not found.');
        }

        $stmt = $this->pdo->prepare('SELECT transaction_id FROM payments WHERE order_id = ?');
        $stmt->execute([$order->id]);
        $storedIdentifier = $stmt->fetchColumn() ?: null;

        $gateway = null;
        $submitted = null;
        if ($gatewayName === 'paypal') {
            $gateway = $this->gateways->make('paypal');
            $submitted = $submittedToken;
        } elseif ($gatewayName === 'credit_card' && $submittedSessionId) {
            $gateway = $this->gateways->make('credit_card');
            $submitted = $submittedSessionId;
        }

        if ($gateway instanceof CapturableGateway) {
            $captureResult = $gateway->capture($storedIdentifier, $submitted, (float)$order->total);
            if ($captureResult->success) {
                $order->markPaid($captureResult->transactionId, $captureResult->rawResponse, $this->pdo);
            }
        }

        return $order;
    }

    private function generateOrderNumber(): string
    {
        return 'SR' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
    }
}
