<?php

namespace ShopRex\Controllers\Storefront;

use ShopRex\Core\Auth\CustomerAuth;
use ShopRex\Core\Container;
use ShopRex\Core\Controller;
use ShopRex\Core\Request;
use ShopRex\Core\Response;
use ShopRex\Models\Order;
use ShopRex\Models\WithdrawalRequest;
use ShopRex\Services\I18n;
use ShopRex\Services\Mailer;
use ShopRex\Services\SettingsRepository;

/**
 * New in v2.00 - the functional self-service right-of-withdrawal flow
 * (see the 'right-of-withdrawal' CMS page for the disclosure text this
 * pairs with). Only the order's own logged-in customer can withdraw
 * (unlike order confirmation's 3-way grant, there's no admin-on-behalf-of
 * or guest-just-placed path here - withdrawing is a customer-initiated
 * legal act, not a viewing permission).
 */
final class WithdrawalController extends Controller
{
    private readonly \PDO $pdo; // Raw DB handle for the item-listing query below.
    private readonly SettingsRepository $settings; // Read here for whatever setting WithdrawalRequest::calculateDeadline() needs (the withdrawal window length).

    public function __construct(Request $request, Container $container)
    {
        parent::__construct($request, $container);
        $this->pdo = $container->make(\PDO::class);
        $this->settings = $container->make(SettingsRepository::class);
    }

    /** Shows the withdrawal form for one order: which items are eligible, the calculated deadline, and any existing request already on file for it. */
    public function show(Request $request): Response
    {
        if ($guard = $this->requireCustomerLogin()) {
            return $guard;
        }
        [$order, $errorResponse] = $this->loadOwnedOrder($request);
        if ($errorResponse) {
            return $errorResponse;
        }

        $existing = WithdrawalRequest::findByOrder($order->id);
        $deadline = WithdrawalRequest::calculateDeadline($order, $this->settings);
        $items = $this->allItemRows($order);

        return $this->render('withdrawal/show', [
            'order' => $order, 'items' => $items, 'existing' => $existing,
            'deadline' => $deadline, 'pastDeadline' => new \DateTimeImmutable() > $deadline,
            'pageTitle' => __('withdrawal.title'),
        ]);
    }

    /**
     * Validates and creates a withdrawal request for the order's selected
     * items, then emails both the customer (confirmation) and the shop
     * (notification). Every validation here is re-derived server-side
     * (deadline, item eligibility) rather than trusted from the submitted
     * form - see the SECURITY comment below for why.
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

        // An order can only have one withdrawal request - block a second
        // submission rather than creating a duplicate.
        if (WithdrawalRequest::findByOrder($order->id)) {
            $this->flash('error', __('withdrawal.already_submitted'));
            return $this->redirect('/account/orders/' . urlencode($order->orderNumber) . '/withdrawal');
        }

        // SECURITY: never trust the client-submitted deadline check or item
        // selection - re-derive both server-side, same posture as
        // checkout's shipping-cost re-derivation and the cart's option-
        // value ownership check.
        $deadline = WithdrawalRequest::calculateDeadline($order, $this->settings);
        if (new \DateTimeImmutable() > $deadline) {
            $this->flash('error', __('withdrawal.past_deadline'));
            return $this->redirect('/account/orders/' . urlencode($order->orderNumber) . '/withdrawal');
        }

        // Build the set of item ids that are actually allowed to be
        // withdrawn (array_filter keeps only non-hygiene items, array_column
        // then pulls out just their ids), then intersect that against
        // whatever ids the customer's checkboxes submitted - array_intersect
        // means any submitted id that ISN'T in the eligible set (e.g. a
        // hygiene product's id tampered into the form) is silently dropped
        // rather than accepted. array_values() re-indexes the result from 0
        // since array_intersect() preserves the original (non-sequential) keys.
        $eligibleIds = array_column(array_filter($this->allItemRows($order), fn ($row) => !$row['is_hygiene_product']), 'id');
        $submittedIds = array_map('intval', (array)$request->post('items', []));
        $validIds = array_values(array_intersect($submittedIds, $eligibleIds));

        if (!$validIds) {
            $this->flash('error', __('withdrawal.select_at_least_one_item'));
            return $this->redirect('/account/orders/' . urlencode($order->orderNumber) . '/withdrawal');
        }

        $reason = trim((string)$request->post('reason', ''));
        $customer = CustomerAuth::current();
        $withdrawal = WithdrawalRequest::createFor($order, $customer, $reason, $validIds, $this->pdo, $this->settings);

        // From here on, the withdrawal request itself already exists and
        // is the part that actually matters - the two notification emails
        // are best-effort. Both do their own DB writes (template lookup +
        // email_log insert) against a PDO connection configured to throw
        // on any SQL error (config/database.php), so wrapping this in one
        // try/catch means a failed query here can't turn into an uncaught
        // 500 that leaves the customer with no confirmation their request
        // was actually received, or tempts them into resubmitting -
        // WithdrawalRequest::findByOrder() above already blocks a genuine
        // second submission, so a resubmit after a scary error page would
        // just fail anyway, with no way to tell the request in fact went through.
        try {
            $lang = I18n::current();
            $received = Mailer::render('withdrawal_request_received', $lang, [
                'customer_name' => e($customer['first_name'] ?? ''), 'order_number' => e($order->orderNumber),
            ]);
            Mailer::send($customer['email'] ?? $order->customerEmail, $received['subject'], $received['html'], 'withdrawal_request_received', $order->id);

            $shopEmail = $this->settings->get('shop_email', ADMIN_EMAIL);
            $notifyShop = Mailer::render('withdrawal_request_notify_shop', $lang, [
                'order_number' => e($order->orderNumber), 'reason' => e($reason ?: '(none given)'),
            ]);
            Mailer::send((string)$shopEmail, $notifyShop['subject'], $notifyShop['html'], 'withdrawal_request_notify_shop', $order->id);
        } catch (\Throwable $e) {
            error_log('Withdrawal request #' . $withdrawal->id . ' created, but notification step failed: ' . $e->getMessage());
        }

        $this->flash('success', __('withdrawal.submitted'));
        return $this->redirect('/account/orders/' . urlencode($order->orderNumber) . '/withdrawal');
    }

    /**
     * Looks up the order by its number from the URL and confirms the
     * currently-logged-in customer actually owns it, returning either
     * [order, null] to proceed or [null, errorResponse] for the caller to
     * return immediately. Both show() and submit() are gated behind
     * requireCustomerLogin() already, so $customer here is always the
     * logged-in customer, not a guest - see the class docblock for why
     * withdrawal has no guest/admin access path, unlike order confirmation.
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
        // Ownership check - without this, any logged-in customer could
        // withdraw from any order just by knowing/guessing its order
        // number in the URL.
        if (!$isOwner) {
            return [null, Response::html(__('order.not_found_text'), 403)];
        }
        return [$order, null];
    }

    /**
     * Every item on the order, each carrying an is_hygiene_product flag
     * (0 if the product was since deleted - can't prove it's excluded, so
     * it's treated as withdrawable). The view renders a checkbox for
     * eligible items and a fixed disclosure sentence for hygiene ones;
     * submit() re-derives the eligible-id set itself rather than trusting
     * which checkboxes came back.
     */
    private function allItemRows(Order $order): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT oi.*, COALESCE(p.is_hygiene_product, 0) AS is_hygiene_product
             FROM order_items oi
             LEFT JOIN products p ON p.id = oi.product_id
             WHERE oi.order_id = ?'
        );
        $stmt->execute([$order->id]);
        return $stmt->fetchAll();
    }
}
