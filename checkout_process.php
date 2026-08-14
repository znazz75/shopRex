<?php
require_once __DIR__ . '/includes/bootstrap.php';

// ---------------------------------------------------------------
// Step 2: gateway return / capture (GET, no CSRF token available here
// since the browser is redirected back by PayPal/Stripe)
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'capture') {
    handleCapture($_GET['gateway'] ?? '', $_GET['order'] ?? '', $_GET['session_id'] ?? null);
    exit;
}

// ---------------------------------------------------------------
// Step 1: place the order (POST from checkout.php)
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(rtrim(SITE_URL, '/') . '/checkout.php');
}
requireCsrf();

$cart = Cart::getItems();
$items = $cart['items'];
if (empty($items)) {
    setFlash('error', 'Your cart is empty.');
    redirect(rtrim(SITE_URL, '/') . '/cart.php');
}

$customer = currentCustomer();

$email = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);
$paymentMethod = $_POST['payment_method'] ?? '';
// Re-validate the payment method server-side rather than trusting the
// submitted value - paypal/credit_card/bank_transfer must currently be
// enabled (Admin -> Settings -> Payment Methods), and invoice must have
// been explicitly granted to this specific logged-in customer (Admin ->
// Customers -> [customer] -> Payment). See includes/functions.php.
$paymentMethodAllowed = match ($paymentMethod) {
    'paypal', 'credit_card', 'bank_transfer' => isPaymentMethodEnabled($paymentMethod),
    'invoice' => customerCanPayOnInvoice($customer),
    default => false,
};
if (!$email || !$paymentMethodAllowed) {
    setFlash('error', 'Please fill in all required fields.');
    redirect(rtrim(SITE_URL, '/') . '/checkout.php');
}

$shippingName = trim($_POST['shipping_name'] ?? '');
$shippingAddress1 = trim($_POST['shipping_address1'] ?? '');
$shippingAddress2 = trim($_POST['shipping_address2'] ?? '');
$shippingCity = trim($_POST['shipping_city'] ?? '');
$shippingPostal = trim($_POST['shipping_postal_code'] ?? '');
$shippingCountry = trim($_POST['shipping_country'] ?? '');
$customerNotes = trim($_POST['customer_notes'] ?? '');

if ($shippingName === '' || $shippingAddress1 === '' || $shippingCity === '' || $shippingPostal === '' || $shippingCountry === '') {
    setFlash('error', 'Please complete the shipping address.');
    redirect(rtrim(SITE_URL, '/') . '/checkout.php');
}

$subtotal = $cart['subtotal'];

// Shipping cost/method is always recomputed server-side from the posted
// method id - never trust a client-submitted price (same principle as the
// price_entry_mode conversion in admin/product_edit.php).
$shippingMethodId = (int)($_POST['shipping_method_id'] ?? 0);
$cartWeightKg = Cart::getWeightKg();
$totalQuantity = Cart::count();
$shippingMethodName = null;
$shippingCost = 0.0;
if ($shippingMethodId) {
    $methodStmt = db()->prepare('SELECT name FROM shipping_methods WHERE id = ? AND is_active = 1');
    $methodStmt->execute([$shippingMethodId]);
    $shippingMethodName = $methodStmt->fetchColumn() ?: null;
    if ($shippingMethodName === null) {
        $shippingMethodId = null;
    } else {
        $shippingCost = Cart::calculateShippingForMethod($shippingMethodId, $cartWeightKg, $subtotal, $totalQuantity);
    }
} else {
    $shippingMethodId = null;
}

$tax = $cart['tax_total'];
$total = $subtotal + $shippingCost + $tax;

// Trial orders placed by an Admin -> Customers -> Test Users account: no
// real gateway call, no stock decrement, excluded from financial reports.
// See includes/PaymentGateway.php TestGateway and admin/finance.php.
$isTestOrder = $customer && !empty($customer['is_test_account']);

$pdo = db();

try {
    $pdo->beginTransaction();

    // Re-check stock for every line before committing to the order.
    foreach ($items as $item) {
        if ($item['quantity'] > $item['available_stock']) {
            throw new RuntimeException('"' . $item['name'] . '" only has ' . $item['available_stock'] . ' left in stock.');
        }
    }

    $orderNumber = generateOrderNumber();

    $stmt = $pdo->prepare(
        'INSERT INTO orders (order_number, customer_id, guest_email, status, payment_method, payment_status, is_test_order, language,
                              subtotal, shipping_cost, shipping_method_id, shipping_method_name, tax_total, total,
                              shipping_name, shipping_address1, shipping_address2, shipping_city, shipping_postal_code, shipping_country,
                              billing_same_as_shipping, customer_notes)
         VALUES (?, ?, ?, "pending", ?, "pending", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)'
    );
    $stmt->execute([
        $orderNumber, $customer['id'] ?? null, $email, $paymentMethod, $isTestOrder ? 1 : 0, getCurrentLanguage(),
        $subtotal, $shippingCost, $shippingMethodId, $shippingMethodName, $tax, $total,
        $shippingName, $shippingAddress1, $shippingAddress2, $shippingCity, $shippingPostal, $shippingCountry,
        $customerNotes,
    ]);
    $orderId = (int)$pdo->lastInsertId();

    $itemStmt = $pdo->prepare(
        'INSERT INTO order_items (order_id, product_id, product_name, option_summary, quantity, unit_price, total_price, tax_rate_percent, tax_amount)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stockStmt = $pdo->prepare('UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ?');
    $variantStockStmt = $pdo->prepare('UPDATE product_variants SET stock_quantity = stock_quantity - ? WHERE id = ?');
    // Legacy fallback only (see includes/Cart.php findVariant()'s fallback
    // path) - combinations that predate the variant matrix feature.
    $optStockStmt = $pdo->prepare('UPDATE product_option_values SET stock_quantity = stock_quantity - ? WHERE id = ?');
    $logStmt = $pdo->prepare(
        'INSERT INTO inventory_log (product_id, option_value_id, product_variant_id, change_qty, reason, reference, is_test) VALUES (?, ?, ?, ?, "sale", ?, ?)'
    );

    foreach ($items as $item) {
        $itemStmt->execute([
            $orderId, $item['product_id'], $item['name'], $item['option_label'],
            $item['quantity'], $item['unit_price'], $item['line_total'],
            $item['tax_rate'], $item['tax_amount'],
        ]);

        // Test orders are still written to the inventory log (so the trial
        // run is visible/auditable) but never actually decrement stock.
        if (empty($item['option_value_ids'])) {
            // Option-less product: stock lives on products.stock_quantity.
            if (!$isTestOrder) {
                $stockStmt->execute([$item['quantity'], $item['product_id']]);
            }
            $logStmt->execute([$item['product_id'], null, null, -$item['quantity'], $orderNumber, $isTestOrder ? 1 : 0]);
        } elseif ($item['variant_id']) {
            // Has options and a real variant match: the combination is the
            // stock-tracking unit (see product_variants in sql/schema.sql).
            if (!$isTestOrder) {
                $variantStockStmt->execute([$item['quantity'], $item['variant_id']]);
            }
            $logStmt->execute([$item['product_id'], null, $item['variant_id'], -$item['quantity'], $orderNumber, $isTestOrder ? 1 : 0]);
        } else {
            // Legacy fallback: options exist but no variant matrix was ever
            // defined for this product - decrement each chosen value's own
            // stock number like the old (pre-variants) behavior.
            foreach ($item['option_value_ids'] as $optionValueId) {
                if (!$isTestOrder) {
                    $optStockStmt->execute([$item['quantity'], $optionValueId]);
                }
                $logStmt->execute([$item['product_id'], $optionValueId, null, -$item['quantity'], $orderNumber, $isTestOrder ? 1 : 0]);
            }
        }
    }

    $paymentStmt = $pdo->prepare(
        'INSERT INTO payments (order_id, payment_method, amount, currency, status) VALUES (?, ?, ?, ?, "pending")'
    );
    $paymentStmt->execute([$orderId, $paymentMethod, $total, getSetting('currency', 'EUR')]);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    setFlash('error', 'Could not place order: ' . $e->getMessage());
    redirect(rtrim(SITE_URL, '/') . '/cart.php');
}

// Order + items are committed. Now kick off the payment gateway (or, for a
// test order, the no-op TestGateway that never calls out to anywhere).
$order = fetchOrderByNumber($orderNumber);
$orderItems = fetchOrderItems($orderId);

$gateway = $isTestOrder ? new TestGateway() : getPaymentGateway($paymentMethod);
$result = $gateway->start($order, $orderItems);

if (!empty($result['transaction_id'])) {
    $pdo->prepare('UPDATE payments SET transaction_id = ? WHERE order_id = ?')->execute([$result['transaction_id'], $orderId]);
}

if (($result['status'] ?? '') === 'completed') {
    markOrderPaid($order, $result['transaction_id'] ?? null, $isTestOrder ? 'Test order - simulated payment, no real transaction was processed.' : 'Captured immediately.');
    $order = fetchOrderByNumber($orderNumber);
}

// Generate the invoice PDF (in the order's language) before emailing the
// confirmation, so it can go out as an attachment - see
// includes/InvoiceGenerator.php and Mailer::sendOrderConfirmation().
try {
    InvoiceGenerator::generateForOrder($order, $orderItems);
} catch (Throwable $e) {
    error_log('Invoice generation failed for order ' . $orderNumber . ': ' . $e->getMessage());
}

// Bank transfer (and any gateway that could not be reached) stays "pending"
// and the customer is sent straight to the confirmation page with instructions.
Cart::clear();
Mailer::sendOrderConfirmation($order, $orderItems);

if (!empty($result['redirect_url'])) {
    redirect($result['redirect_url']);
}

redirect(rtrim(SITE_URL, '/') . '/order_confirmation.php?order=' . urlencode($orderNumber));

// =================================================================
// Helpers
// =================================================================

function fetchOrderByNumber(string $orderNumber): array
{
    $stmt = db()->prepare(
        "SELECT o.*, COALESCE(c.email, o.guest_email) AS customer_email
         FROM orders o LEFT JOIN customers c ON c.id = o.customer_id
         WHERE o.order_number = ?"
    );
    $stmt->execute([$orderNumber]);
    $order = $stmt->fetch();
    if (!$order) {
        http_response_code(404);
        die('Order not found.');
    }
    return $order;
}

function fetchOrderItems(int $orderId): array
{
    $stmt = db()->prepare('SELECT * FROM order_items WHERE order_id = ?');
    $stmt->execute([$orderId]);
    return $stmt->fetchAll();
}

function markOrderPaid(array $order, ?string $transactionId, string $gatewayResponse): void
{
    $pdo = db();
    $pdo->prepare('UPDATE orders SET status = "processing", payment_status = "paid" WHERE id = ?')->execute([$order['id']]);
    $pdo->prepare(
        'UPDATE payments SET status = "completed", transaction_id = COALESCE(?, transaction_id), gateway_response = ? WHERE order_id = ?'
    )->execute([$transactionId, $gatewayResponse, $order['id']]);

    // Test orders never touch the financial ledger - admin/finance.php and
    // the dashboard revenue figures stay accurate for real sales only.
    if (empty($order['is_test_order'])) {
        $pdo->prepare('INSERT INTO transactions (order_id, type, amount, note) VALUES (?, "sale", ?, "Payment captured")')
            ->execute([$order['id'], $order['total']]);
    }
}

function handleCapture(string $gateway, string $orderNumber, ?string $sessionId): void
{
    $order = fetchOrderByNumber($orderNumber);

    if ($gateway === 'paypal') {
        $paypalOrderId = $_GET['token'] ?? null; // PayPal appends its own order id as ?token=
        $stmt = db()->prepare('SELECT transaction_id FROM payments WHERE order_id = ?');
        $stmt->execute([$order['id']]);
        $txId = $paypalOrderId ?: ($stmt->fetchColumn() ?: null);

        if ($txId) {
            $captureResponse = capturePayPalOrder($txId);
            if (($captureResponse['status'] ?? '') === 'COMPLETED') {
                markOrderPaid($order, $txId, json_encode($captureResponse));
            }
        }
    } elseif ($gateway === 'credit_card' && $sessionId) {
        $sessionData = fetchStripeSession($sessionId);
        if (($sessionData['payment_status'] ?? '') === 'paid') {
            markOrderPaid($order, $sessionId, json_encode($sessionData));
        }
    }

    redirect(rtrim(SITE_URL, '/') . '/order_confirmation.php?order=' . urlencode($orderNumber));
}

function capturePayPalOrder(string $paypalOrderId): array
{
    $ch = curl_init(paypalApiBase() . '/v1/oauth2/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_USERPWD        => paypalClientId() . ':' . paypalClientSecret(),
        CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
        CURLOPT_TIMEOUT        => 15,
    ]);
    $tokenResponse = json_decode((string)curl_exec($ch), true);
    curl_close($ch);
    $token = $tokenResponse['access_token'] ?? null;
    if (!$token) {
        return [];
    }

    $ch = curl_init(paypalApiBase() . '/v2/checkout/orders/' . urlencode($paypalOrderId) . '/capture');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
        CURLOPT_POSTFIELDS     => '{}',
        CURLOPT_TIMEOUT        => 15,
    ]);
    $response = json_decode((string)curl_exec($ch), true);
    curl_close($ch);
    return $response ?: [];
}

function fetchStripeSession(string $sessionId): array
{
    $ch = curl_init('https://api.stripe.com/v1/checkout/sessions/' . urlencode($sessionId));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => stripeSecretKey() . ':',
        CURLOPT_TIMEOUT        => 15,
    ]);
    $response = json_decode((string)curl_exec($ch), true);
    curl_close($ch);
    return $response ?: [];
}
