<?php

namespace ShopRex\Services;

use ShopRex\Models\Cart;
use ShopRex\Models\Order;
use ShopRex\Payment\CapturableGateway;
use ShopRex\Payment\PaymentGatewayFactory;
use ShopRex\Payment\TestGateway;
// InvoiceGenerator/Mailer are unqualified below - both already live in
// this same ShopRex\Services namespace, so no `use` import is needed.

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
        private readonly NumberSequenceService $sequences,
    ) {
    }

    /**
     * Ported from checkout_process.php:16-223.
     * @throws CheckoutException on any validation failure (mirrors the
     *         original's setFlash()+redirect() early exits).
     *
     * This is the entire "turn a cart into a real order" pipeline: re-validate
     * everything server-side (never trust posted prices/methods), reserve
     * stock, write the order + line items + payment row atomically, then kick
     * off the payment gateway and send the confirmation email/invoice. Kept
     * as one long method (rather than split into many tiny private ones)
     * because the original checkout_process.php was one long procedural flow
     * and splitting it up risked losing track of ordering-sensitive steps
     * (e.g. stock must be locked before the gateway is contacted) during the
     * port - see class docblock.
     */
    public function placeOrder(array $post, ?array $customer): PlaceOrderResult
    {
        // Cart::getItems() re-derives prices/stock from the database on every
        // read (see CLAUDE.md) rather than trusting whatever was last shown
        // to the browser - this is what keeps the eventual order total honest.
        $cart = $this->cart->getItems();
        $items = $cart['items'];
        if (empty($items)) {
            throw new CheckoutException('Your cart is empty.', '/cart');
        }

        // filter_var(..., FILTER_VALIDATE_EMAIL) returns false for anything
        // that isn't a well-formed address, which is falsy - the `!$email`
        // check below catches both "missing" and "malformed" in one go.
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

        // shipping_address2 is optional (apartment/suite line); everything
        // else in the shipping address is required to fulfill an order.
        if ($shippingName === '' || $shippingAddress1 === '' || $shippingCity === '' || $shippingPostal === '' || $shippingCountry === '') {
            throw new CheckoutException('Please complete the shipping address.');
        }

        // Subtotal/tax come straight from Cart::getItems()'s own
        // server-side-recalculated totals, never from anything the client posted.
        $subtotal = $cart['subtotal'];

        // Shipping cost/method is always recomputed server-side from the
        // posted method id - never trust a client-submitted price.
        $shippingMethodId = (int)($post['shipping_method_id'] ?? 0);
        $cartWeightKg = $this->cart->getWeightKg();
        $totalQuantity = $this->cart->count();
        $shippingMethodName = null;
        $shippingCost = 0.0;
        if ($shippingMethodId) {
            // Only an active shipping method may be used - if the posted ID
            // doesn't match one, or it's since been deactivated, treat it as
            // "no shipping method chosen" rather than trusting the ID blindly.
            $methodStmt = $this->pdo->prepare('SELECT name FROM shipping_methods WHERE id = ? AND is_active = 1');
            $methodStmt->execute([$shippingMethodId]);
            $shippingMethodName = $methodStmt->fetchColumn() ?: null;
            if ($shippingMethodName === null) {
                $shippingMethodId = null;
            } else {
                // The actual cost is computed server-side from the cart's
                // real weight/subtotal/quantity, not accepted from the client.
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
            // Everything from here to commit() happens as one atomic
            // transaction - the order row, its line items, stock
            // decrements, and the payment row must all succeed together or
            // not at all, otherwise the database could end up with e.g. an
            // order that was charged for stock that was never actually
            // reserved.
            $this->pdo->beginTransaction();

            // Re-check stock for every line before committing to the order.
            // This still isn't the whole story: available_stock was read
            // outside this transaction, so a second checkout for the same
            // item running concurrently could pass this same check before
            // either has decremented anything (classic check-then-act race,
            // not closed by wrapping the check alone in a transaction) - the
            // UPDATE ... WHERE stock_quantity >= ? guards below are what
            // actually close it, by making the decrement itself conditional
            // and re-checked via rowCount().
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
            // lastInsertId() reads back the auto-increment ID the order row
            // was just assigned, needed below to attach line items/payments to it.
            $orderId = (int)$this->pdo->lastInsertId();

            $itemStmt = $this->pdo->prepare(
                'INSERT INTO order_items (order_id, product_id, product_name, option_summary, quantity, unit_price, total_price, tax_rate_percent, tax_amount)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            // The "AND stock_quantity >= ?" guard (plus the rowCount() check
            // at each call site below) is what actually prevents overselling
            // under concurrent checkouts for the same item - the plain
            // pre-check above can't, since it reads stock before this
            // transaction starts. A guarded UPDATE is atomic per-row in
            // MySQL/InnoDB, so two simultaneous transactions for the last
            // unit can't both succeed: whichever commits second sees the
            // row already short and its UPDATE simply matches zero rows.
            $stockStmt = $this->pdo->prepare('UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ? AND stock_quantity >= ?');
            $variantStockStmt = $this->pdo->prepare('UPDATE product_variants SET stock_quantity = stock_quantity - ? WHERE id = ? AND stock_quantity >= ?');
            // Legacy fallback only (see Models\Cart::findVariant()'s fallback path).
            $optStockStmt = $this->pdo->prepare('UPDATE product_option_values SET stock_quantity = stock_quantity - ? WHERE id = ? AND stock_quantity >= ?');
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
                //
                // Which stock column gets decremented depends on how this
                // line item was configured: a plain product with no options,
                // a product tracked via a specific variant combination
                // (product_variants), or - the legacy fallback path - a set
                // of individual option value IDs (see Models\Cart's
                // docblock and docs/SECURITY_AUDIT.md finding #1 for why
                // that fallback path must stay carefully scoped elsewhere).
                if (empty($item['option_value_ids'])) {
                    if (!$isTestOrder) {
                        $stockStmt->execute([$item['quantity'], $item['product_id'], $item['quantity']]);
                        // Zero rows matched means a concurrent order already
                        // took the remaining stock between our pre-check
                        // above and this UPDATE - abort and roll back rather
                        // than log a sale that was never actually reserved.
                        if ($stockStmt->rowCount() === 0) {
                            throw new \RuntimeException('"' . $item['name'] . '" just sold out - please try again.');
                        }
                    }
                    $logStmt->execute([$item['product_id'], null, null, -$item['quantity'], $orderNumber, $isTestOrder ? 1 : 0]);
                } elseif ($item['variant_id']) {
                    if (!$isTestOrder) {
                        $variantStockStmt->execute([$item['quantity'], $item['variant_id'], $item['quantity']]);
                        if ($variantStockStmt->rowCount() === 0) {
                            throw new \RuntimeException('"' . $item['name'] . '" just sold out - please try again.');
                        }
                    }
                    $logStmt->execute([$item['product_id'], null, $item['variant_id'], -$item['quantity'], $orderNumber, $isTestOrder ? 1 : 0]);
                } else {
                    foreach ($item['option_value_ids'] as $optionValueId) {
                        if (!$isTestOrder) {
                            $optStockStmt->execute([$item['quantity'], $optionValueId, $item['quantity']]);
                            if ($optStockStmt->rowCount() === 0) {
                                throw new \RuntimeException('"' . $item['name'] . '" just sold out - please try again.');
                            }
                        }
                        $logStmt->execute([$item['product_id'], $optionValueId, null, -$item['quantity'], $orderNumber, $isTestOrder ? 1 : 0]);
                    }
                }
            }

            // The payments row starts "pending" regardless of method - even
            // for gateways that end up completing immediately below, so
            // there's always exactly one payments row per order to update
            // rather than conditionally inserting different shapes.
            $paymentStmt = $this->pdo->prepare(
                'INSERT INTO payments (order_id, payment_method, amount, currency, status) VALUES (?, ?, ?, ?, "pending")'
            );
            $paymentStmt->execute([$orderId, $paymentMethod, $total, $this->settings->get('currency', 'EUR')]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            // Any failure anywhere above (stock shortfall, DB error, etc.)
            // undoes the whole transaction - no half-created order is ever
            // left behind - and is re-surfaced as a CheckoutException so the
            // controller can flash it and send the customer back to their cart.
            $this->pdo->rollBack();
            throw new CheckoutException('Could not place order: ' . $e->getMessage(), '/cart');
        }

        $order = Order::findByNumber($orderNumber);

        // Order + items are committed. Now kick off the payment gateway (or,
        // for a test order, the no-op TestGateway that never calls out
        // anywhere).
        $orderItems = $order->items();
        $gateway = $isTestOrder ? new TestGateway() : $this->gateways->make($paymentMethod);
        // start() is what actually talks to PayPal/Stripe (creates their
        // hosted checkout session) or, for bank transfer, just returns
        // "pending" with no external call at all.
        $result = $gateway->start($order->toRow(), $orderItems);

        // Records the gateway's own transaction/session identifier on the
        // payments row - this is the value handleCapture() later trusts
        // instead of anything a return-URL query parameter claims (see
        // handleCapture()'s docblock and docs/SECURITY_AUDIT.md finding #2).
        if (!empty($result['transaction_id'])) {
            $this->pdo->prepare('UPDATE payments SET transaction_id = ? WHERE order_id = ?')->execute([$result['transaction_id'], $orderId]);
        }

        // Some methods (bank transfer, TestGateway, or a gateway configured
        // to auto-capture) report the order as fully paid immediately rather
        // than needing the customer to be redirected off-site first.
        if (($result['status'] ?? '') === 'completed') {
            $order->markPaid(
                $result['transaction_id'] ?? null,
                $isTestOrder ? 'Test order - simulated payment, no real transaction was processed.' : 'Captured immediately.',
                $this->pdo
            );
            // Re-fetches the order so the in-memory object reflects the
            // payment_status/status columns markPaid() just wrote.
            $order = Order::findByNumber($orderNumber);
        }

        // Generate the invoice PDF (in the order's language) before emailing
        // the confirmation, so it can go out as an attachment.
        try {
            InvoiceGenerator::generateForOrder($order->toRow(), $orderItems, $this->sequences);
        } catch (\Throwable $e) {
            error_log('Invoice generation failed for order ' . $orderNumber . ': ' . $e->getMessage());
        }

        // Bank transfer (and any gateway that could not be reached) stays
        // "pending" and the customer is sent straight to the confirmation
        // page with instructions.
        $this->cart->clear();
        Mailer::sendOrderConfirmation($order->toRow(), $orderItems);

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
        // $orderNumber comes straight from the gateway return URL's query
        // string, so it's attacker-controllable - see docs/SECURITY_AUDIT.md
        // finding #2. That's fine BY ITSELF because nothing below trusts the
        // URL for anything except "which order to look up"; what actually
        // gets captured/verified is tied to $storedIdentifier, read from the
        // database row that was written at placeOrder() time, not from the
        // URL.
        $order = Order::findByNumber($orderNumber);
        if (!$order) {
            throw new \RuntimeException('Order not found.');
        }

        // The gateway transaction/session ID that THIS order's own start()
        // call actually stored - the only value that gets passed to
        // capture() as the trusted "what to verify against" identifier.
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

        // Only PayPal/CreditCard implement CapturableGateway (bank
        // transfer/invoice have nothing to "capture" - they're already
        // pending and settled manually) - this check both narrows the type
        // and skips capture entirely for methods that don't support it.
        if ($gateway instanceof CapturableGateway) {
            // capture() is where the actual fix for finding #2 lives: it
            // verifies $submitted (the attacker-controllable query value)
            // against $storedIdentifier (the trusted, server-recorded
            // value) AND that the captured amount matches $order->total,
            // before ever reporting success - see PayPalGateway::capture()/
            // CreditCardGateway::capture().
            $captureResult = $gateway->capture($storedIdentifier, $submitted, (float)$order->total);
            if ($captureResult->success) {
                $order->markPaid($captureResult->transactionId, $captureResult->rawResponse, $this->pdo);
            }
        }

        return $order;
    }

    /** Generates the human-facing order number shown to customers/admins (e.g. "SR20260815-A1B2C3") - a date prefix plus 3 random bytes hex-encoded, not a sequential ID, so order numbers can't be easily guessed/enumerated in sequence. */
    private function generateOrderNumber(): string
    {
        return 'SR' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
    }
}
