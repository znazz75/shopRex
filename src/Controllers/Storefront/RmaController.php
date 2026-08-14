<?php

namespace ShopRex\Controllers\Storefront;

use ShopRex\Core\Auth\CustomerAuth;
use ShopRex\Core\Container;
use ShopRex\Core\Controller;
use ShopRex\Core\Request;
use ShopRex\Core\Response;
use ShopRex\Models\Order;
use ShopRex\Models\Product;
use ShopRex\Models\RmaTicket;
use ShopRex\Services\I18n;
use ShopRex\Services\SettingsRepository;

/**
 * New in v2.00 - RMA / defect tickets. Item-level (a defect claim is
 * always about one specific product), warranty-window eligibility per
 * Models\RmaTicket::isEligible(), distinct from WithdrawalController's
 * fixed 14-day no-reason-needed window.
 */
final class RmaController extends Controller
{
    private const MAX_ATTACHMENTS = 5;
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];
    private const MAX_FILE_BYTES = 5 * 1024 * 1024;

    private readonly \PDO $pdo;
    private readonly SettingsRepository $settings;

    public function __construct(Request $request, Container $container)
    {
        parent::__construct($request, $container);
        $this->pdo = $container->make(\PDO::class);
        $this->settings = $container->make(SettingsRepository::class);
    }

    public function show(Request $request): Response
    {
        if ($guard = $this->requireCustomerLogin()) {
            return $guard;
        }
        [$order, $errorResponse] = $this->loadOwnedOrder($request);
        if ($errorResponse) {
            return $errorResponse;
        }

        $items = $this->itemsWithEligibility($order);
        $ticketsByItem = $this->existingTicketsByItem($order);

        return $this->render('rma/show', [
            'order' => $order, 'items' => $items, 'ticketsByItem' => $ticketsByItem,
            'pageTitle' => __('rma.title'),
        ]);
    }

    public function submit(Request $request): Response
    {
        if ($guard = $this->requireCustomerLogin()) {
            return $guard;
        }
        if ($csrfFailure = $this->requireCsrf()) {
            return $csrfFailure;
        }
        [$order, $errorResponse] = $this->loadOwnedOrder($request);
        if ($errorResponse) {
            return $errorResponse;
        }

        $orderItemId = (int)$request->post('order_item_id', 0);
        $claimType = $request->post('warranty_claim_type', 'statutory') === 'manufacturer' ? 'manufacturer' : 'statutory';
        $description = trim((string)$request->post('defect_description', ''));

        $item = null;
        foreach ($this->itemsWithEligibility($order) as $row) {
            if ((int)$row['id'] === $orderItemId) {
                $item = $row;
                break;
            }
        }

        if (!$item || $description === '') {
            $this->flash('error', __('rma.invalid_submission'));
            return $this->redirect('/account/orders/' . urlencode($order->orderNumber) . '/rma');
        }

        // SECURITY: re-derive eligibility server-side from the product's
        // actual configured warranty months - never trust that the form
        // only offered the claim type it should have.
        $eligible = $claimType === 'manufacturer' ? $item['manufacturer_eligible'] : $item['statutory_eligible'];
        if (!$eligible) {
            $this->flash('error', __('rma.not_eligible'));
            return $this->redirect('/account/orders/' . urlencode($order->orderNumber) . '/rma');
        }

        $customer = CustomerAuth::current();
        $ticket = RmaTicket::createFor($order->id, $orderItemId, $customer, $claimType, $description, $this->pdo);

        $uploadedCount = 0;
        $files = $request->files('photos');
        $names = (array)($files['name'] ?? []);
        for ($i = 0; $i < count($names) && $uploadedCount < self::MAX_ATTACHMENTS; $i++) {
            if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                continue;
            }
            $tmpPath = $files['tmp_name'][$i];
            $originalName = (string)$files['name'][$i];
            $ext = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));

            if (!in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
                continue;
            }
            if (($files['size'][$i] ?? 0) > self::MAX_FILE_BYTES) {
                continue;
            }
            // Content-sniff, not just the extension - matches the fix
            // documented for admin/product_images.php (docs/SECURITY_AUDIT.md
            // finding #6): an attacker-renamed non-image file fails this check.
            if (@getimagesize($tmpPath) === false) {
                continue;
            }

            $dir = dirname(__DIR__, 3) . '/uploads/rma/';
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            $storedName = 'rma-' . $ticket->id . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
            if (move_uploaded_file($tmpPath, $dir . $storedName)) {
                $ticket->addAttachment('rma/' . $storedName, $this->pdo);
                $uploadedCount++;
            }
        }

        $lang = I18n::current();
        $received = \Mailer::render('rma_ticket_received', $lang, [
            'customer_name' => e($customer['first_name'] ?? ''), 'order_number' => e($order->orderNumber),
            'product_name' => e($item['product_name']),
        ]);
        \Mailer::send($customer['email'] ?? $order->customerEmail, $received['subject'], $received['html'], 'rma_ticket_received', $order->id);

        $shopEmail = $this->settings->get('shop_email', ADMIN_EMAIL);
        $notifyShop = \Mailer::render('rma_ticket_notify_shop', $lang, [
            'order_number' => e($order->orderNumber), 'product_name' => e($item['product_name']),
            'warranty_claim_type' => e($claimType), 'defect_description' => nl2br(e($description)),
        ]);
        \Mailer::send((string)$shopEmail, $notifyShop['subject'], $notifyShop['html'], 'rma_ticket_notify_shop', $order->id);

        $this->flash('success', __('rma.submitted'));
        return $this->redirect('/account/orders/' . urlencode($order->orderNumber) . '/rma');
    }

    /** @return array{0: ?Order, 1: ?Response} */
    private function loadOwnedOrder(Request $request): array
    {
        $orderNumber = (string)$request->routeParam('orderNumber', '');
        $order = Order::findByNumber($orderNumber);
        if (!$order) {
            return [null, Response::html(__('order.not_found_text'), 404)];
        }
        $customer = CustomerAuth::current();
        $isOwner = $customer && $order->customerId !== null && (int)$order->customerId === (int)$customer['id'];
        if (!$isOwner) {
            return [null, Response::html(__('order.not_found_text'), 403)];
        }
        return [$order, null];
    }

    /**
     * Every order item plus whether it's currently eligible for a statutory
     * and/or manufacturer RMA claim. Explicit column list rather than
     * `oi.*, p.*` - both tables have an `id` column and PDO would silently
     * resolve that collision by column order, which is fragile to rely on.
     */
    private function itemsWithEligibility(Order $order): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT oi.id, oi.order_id, oi.product_id, oi.product_name, oi.option_summary, oi.quantity, oi.total_price,
                    p.statutory_warranty_months, p.manufacturer_warranty_months
             FROM order_items oi
             LEFT JOIN products p ON p.id = oi.product_id
             WHERE oi.order_id = ?'
        );
        $stmt->execute([$order->id]);
        $rows = $stmt->fetchAll();

        $orderDate = new \DateTimeImmutable($order->createdAt ?? 'now');
        foreach ($rows as &$row) {
            if (!$row['product_id']) {
                $row['statutory_eligible'] = false;
                $row['manufacturer_eligible'] = false;
                continue;
            }
            $product = new Product();
            $product->statutoryWarrantyMonths = (int)$row['statutory_warranty_months'];
            $product->manufacturerWarrantyMonths = $row['manufacturer_warranty_months'] !== null ? (int)$row['manufacturer_warranty_months'] : null;
            $row['statutory_eligible'] = RmaTicket::isEligible($product, 'statutory', $orderDate);
            $row['manufacturer_eligible'] = RmaTicket::isEligible($product, 'manufacturer', $orderDate);
        }
        unset($row);

        return $rows;
    }

    /** @return array<int, array<int, \ShopRex\Models\RmaTicket>> order_item_id => tickets */
    private function existingTicketsByItem(Order $order): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM rma_tickets WHERE order_id = ? ORDER BY created_at DESC');
        $stmt->execute([$order->id]);
        $byItem = [];
        foreach ($stmt->fetchAll() as $row) {
            $byItem[(int)$row['order_item_id']][] = (new RmaTicket())->fill($row);
        }
        return $byItem;
    }
}
