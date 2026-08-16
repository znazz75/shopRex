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
use ShopRex\Services\Mailer;
use ShopRex\Services\NumberSequenceService;
use ShopRex\Services\SettingsRepository;

/**
 * New in v2.00 - RMA / defect tickets. Item-level (a defect claim is
 * always about one specific product), warranty-window eligibility per
 * Models\RmaTicket::isEligible(), distinct from WithdrawalController's
 * fixed 14-day no-reason-needed window.
 */
final class RmaController extends Controller
{
    private const MAX_ATTACHMENTS = 5; // Hard cap on how many defect photos a single ticket can carry.
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp']; // Whitelist of file extensions accepted for attachments - checked alongside the content-sniff below, not instead of it.
    private const MAX_FILE_BYTES = 5 * 1024 * 1024; // 5 MB per-photo upload size limit.

    private readonly \PDO $pdo; // Raw DB handle for the item/eligibility and existing-tickets queries below.
    private readonly SettingsRepository $settings; // Used here only to look up the shop's notification email address.
    private readonly NumberSequenceService $sequences; // Issues each new ticket's rma_number (Admin -> Numbering).

    public function __construct(Request $request, Container $container)
    {
        parent::__construct($request, $container);
        $this->pdo = $container->make(\PDO::class);
        $this->settings = $container->make(SettingsRepository::class);
        $this->sequences = $container->make(NumberSequenceService::class);
    }

    /** Shows the RMA form for one order: every item, whether each is currently eligible for a statutory and/or manufacturer warranty claim, and any tickets already filed against it. */
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

    /**
     * Validates and creates an RMA ticket for one order item, then handles
     * up to MAX_ATTACHMENTS photo uploads for it, and finally emails both
     * the customer (confirmation) and the shop (notification). Eligibility
     * is re-derived server-side (see the SECURITY comment below) rather
     * than trusted from which claim type the form happened to submit.
     */
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
        // Only two valid claim types exist - anything else submitted
        // collapses to 'statutory' rather than being stored as-is.
        $claimType = $request->post('warranty_claim_type', 'statutory') === 'manufacturer' ? 'manufacturer' : 'statutory';
        $description = trim((string)$request->post('defect_description', ''));

        // Confirm the submitted order_item_id genuinely belongs to this
        // order (via itemsWithEligibility(), which is already scoped to
        // $order) rather than trusting an arbitrary id from the form.
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
        $ticket = RmaTicket::createFor($order->id, $orderItemId, $customer, $claimType, $description, $this->pdo, $this->sequences);

        // From here on, the ticket itself already exists and is the part
        // that actually matters - everything below (photo attachments,
        // notification emails) is best-effort. Every step here does its
        // own DB write, and this app's PDO connection is configured to
        // throw on any SQL error (config/database.php), so wrapping this
        // in one try/catch means a single failed insert/query (a dropped
        // connection, a full disk, etc.) can't turn into an uncaught 500
        // that leaves the customer with no confirmation their ticket was
        // actually received, or tempts them into resubmitting a duplicate.
        try {
            $uploadedCount = 0;
            // PHP's multi-file upload format returns parallel arrays
            // (name[]/tmp_name[]/error[]/size[] all indexed the same way)
            // rather than one array per file, so $names/$files are walked by
            // shared index $i below.
            $files = $request->files('photos');
            $names = (array)($files['name'] ?? []);
            for ($i = 0; $i < count($names) && $uploadedCount < self::MAX_ATTACHMENTS; $i++) {
                // Skip any slot that isn't a successfully uploaded file (empty
                // slot, or a PHP-level upload error) rather than treating it as
                // a failure worth reporting - photo attachments are optional.
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
                // Store under a random filename (not the attacker-controlled
                // original name) so nothing about the on-disk path is
                // predictable or guessable, and so two different uploads never
                // collide.
                $storedName = 'rma-' . $ticket->id . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
                if (move_uploaded_file($tmpPath, $dir . $storedName)) {
                    $ticket->addAttachment('rma/' . $storedName, $this->pdo);
                    $uploadedCount++;
                }
            }

            $lang = I18n::current();
            $received = Mailer::render('rma_ticket_received', $lang, [
                'customer_name' => e($customer['first_name'] ?? ''), 'order_number' => e($order->orderNumber),
                'product_name' => e($item['product_name']),
            ]);
            Mailer::send($customer['email'] ?? $order->customerEmail, $received['subject'], $received['html'], 'rma_ticket_received', $order->id);

            $shopEmail = $this->settings->get('shop_email', ADMIN_EMAIL);
            $notifyShop = Mailer::render('rma_ticket_notify_shop', $lang, [
                'order_number' => e($order->orderNumber), 'product_name' => e($item['product_name']),
                'warranty_claim_type' => e($claimType), 'defect_description' => nl2br(e($description)),
            ]);
            Mailer::send((string)$shopEmail, $notifyShop['subject'], $notifyShop['html'], 'rma_ticket_notify_shop', $order->id);
        } catch (\Throwable $e) {
            error_log('RMA ticket #' . $ticket->id . ' created, but attachment/notification step failed: ' . $e->getMessage());
        }

        $this->flash('success', __('rma.submitted'));
        return $this->redirect('/account/orders/' . urlencode($order->orderNumber) . '/rma');
    }

    /**
     * Looks up the order by its number from the URL and confirms the
     * currently-logged-in customer actually owns it, returning either
     * [order, null] to proceed or [null, errorResponse] for the caller to
     * return immediately.
     *
     * @return array{0: ?Order, 1: ?Response}
     */
    private function loadOwnedOrder(Request $request): array
    {
        $orderNumber = (string)$request->routeParam('orderNumber', '');
        $order = Order::findByNumber($orderNumber);
        if (!$order) {
            return [null, Response::html(__('order.not_found_text'), 404)];
        }
        $customer = CustomerAuth::current();
        $isOwner = $customer && $order->customerId !== null && (int)$order->customerId === (int)$customer['id'];
        // Ownership check - without this, any logged-in customer could file
        // an RMA ticket against any order just by knowing/guessing its
        // order number in the URL.
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

        // Eligibility is based on how much time has passed since the order
        // was placed, so it's computed once per request from the order's
        // creation date, not re-read per item.
        $orderDate = new \DateTimeImmutable($order->createdAt ?? 'now');
        foreach ($rows as &$row) {
            // A deleted product leaves no warranty data to check against -
            // treat it as not eligible for either claim type rather than
            // guessing.
            if (!$row['product_id']) {
                $row['statutory_eligible'] = false;
                $row['manufacturer_eligible'] = false;
                continue;
            }
            // Build a throwaway Product instance just to reuse
            // RmaTicket::isEligible()'s warranty-window logic, rather than
            // duplicating that calculation here - only the two warranty
            // fields it actually reads are populated.
            $product = new Product();
            $product->statutoryWarrantyMonths = (int)$row['statutory_warranty_months'];
            $product->manufacturerWarrantyMonths = $row['manufacturer_warranty_months'] !== null ? (int)$row['manufacturer_warranty_months'] : null;
            $row['statutory_eligible'] = RmaTicket::isEligible($product, 'statutory', $orderDate);
            $row['manufacturer_eligible'] = RmaTicket::isEligible($product, 'manufacturer', $orderDate);
        }
        unset($row);

        return $rows;
    }

    /**
     * Groups every RMA ticket already filed against this order by which
     * order item it's for, so the view can show "already filed" status
     * next to each item instead of a flat list. @return array<int,
     * array<int, \ShopRex\Models\RmaTicket>> order_item_id => tickets
     */
    private function existingTicketsByItem(Order $order): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM rma_tickets WHERE order_id = ? ORDER BY created_at DESC');
        $stmt->execute([$order->id]);
        $byItem = [];
        // Appending onto $byItem[id][] (rather than overwriting $byItem[id])
        // groups multiple tickets for the same item together, since one
        // item can have more than one RMA ticket over time.
        foreach ($stmt->fetchAll() as $row) {
            $byItem[(int)$row['order_item_id']][] = (new RmaTicket())->fill($row);
        }
        return $byItem;
    }
}
