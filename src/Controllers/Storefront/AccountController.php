<?php

namespace ShopRex\Controllers\Storefront;

use ShopRex\Core\Auth\CustomerAuth;
use ShopRex\Core\Container;
use ShopRex\Core\Controller;
use ShopRex\Core\Request;
use ShopRex\Core\Response;
use ShopRex\Services\GdprService;

/**
 * The logged-in customer's "My Account" area: order history, and the two
 * GDPR self-service actions (export my data / delete my account). Direct
 * port of account.php + account_export.php + account_delete.php.
 */
final class AccountController extends Controller
{
    private readonly \PDO $pdo; // Raw DB handle for the order-history/invoice-existence queries below.
    private readonly GdprService $gdpr; // Handles the actual data-export and account-deletion logic (GDPR "right to access"/"right to erasure") - kept in a service rather than inline here since Admin also triggers customer deletion via the same service.

    public function __construct(Request $request, Container $container)
    {
        parent::__construct($request, $container);
        $this->pdo = $container->make(\PDO::class);
        $this->gdpr = $container->make(GdprService::class);
    }

    /** The account dashboard - the customer's own order history, plus which of those orders have a downloadable invoice. */
    public function index(Request $request): Response
    {
        if ($guard = $this->requireCustomerLogin()) {
            return $guard;
        }
        $customer = CustomerAuth::current();

        $stmt = $this->pdo->prepare('SELECT * FROM orders WHERE customer_id = ? ORDER BY created_at DESC');
        $stmt->execute([$customer['id']]);
        $orders = $stmt->fetchAll();

        // array_flip + array_column turns the list of order_id rows into a
        // fast "is this order_id a key in the array?" lookup set, so the
        // view can check isset($invoicesByOrder[$id]) instead of scanning
        // a list per order.
        $invStmt = $this->pdo->prepare('SELECT order_id FROM invoices WHERE order_id IN (SELECT id FROM orders WHERE customer_id = ?)');
        $invStmt->execute([$customer['id']]);
        $invoicesByOrder = array_flip(array_column($invStmt->fetchAll(), 'order_id'));

        $pageTitle = __('account.title');
        return $this->render('account/index', compact('customer', 'orders', 'invoicesByOrder', 'pageTitle'));
    }

    /**
     * GDPR "right to access" - streams a downloadable JSON file of
     * everything the shop knows about the logged-in customer.
     * JSON_UNESCAPED_SLASHES/JSON_UNESCAPED_UNICODE keep the output
     * readable (no escaped "\/" or "\uXXXX" sequences) since this is meant
     * for a human to actually open and read, not just for machine parsing.
     */
    public function export(Request $request): Response
    {
        if ($guard = $this->requireCustomerLogin()) {
            return $guard;
        }
        $customer = CustomerAuth::current();
        $data = $this->gdpr->exportData($customer['id']);

        return Response::html((string)json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="my-data-' . date('Y-m-d') . '.json"');
    }

    /** Shows the "are you sure" confirmation page before permanently deleting the account - a separate GET step so account deletion is never a single accidental click/request. */
    public function confirmDelete(Request $request): Response
    {
        if ($guard = $this->requireCustomerLogin()) {
            return $guard;
        }
        return $this->render('account/delete_confirm', ['error' => null, 'pageTitle' => __('account.delete_confirm_title')]);
    }

    /**
     * GDPR "right to erasure" - permanently removes/scrubs the customer's
     * account after re-verifying their password (a second factor beyond
     * just having an active session, since this is irreversible).
     */
    public function delete(Request $request): Response
    {
        if ($guard = $this->requireCustomerLogin()) {
            return $guard;
        }
        if ($csrfFailure = $this->requireCsrf()) {
            return $csrfFailure;
        }

        $customer = CustomerAuth::current();
        $password = (string)$request->post('password', '');

        // Re-confirm the password even though the customer is already
        // logged in - a stolen/left-open session shouldn't be enough to
        // delete the account outright.
        if (!password_verify($password, $customer['password_hash'])) {
            return $this->render('account/delete_confirm', [
                'error' => __('account.delete_wrong_password'), 'pageTitle' => __('account.delete_confirm_title'),
            ]);
        }

        // See GdprService::deleteCustomer()'s own docblock for exactly
        // what "delete" means here - CLAUDE.md notes orders are retained-
        // but-scrubbed, not hard-deleted, so financial/legal records survive.
        $this->gdpr->deleteCustomer($customer['id']);
        $this->request->session()->remove('customer_id');
        $this->request->session()->regenerate();
        CustomerAuth::forget();
        $this->flash('success', __('account.delete_success'));
        return $this->redirect('/');
    }
}
