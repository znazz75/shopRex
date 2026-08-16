<?php

namespace ShopRex\Controllers\Admin;

use ShopRex\Core\Container;
use ShopRex\Core\Request;
use ShopRex\Core\Response;
use ShopRex\Services\GdprService;
use ShopRex\Services\NumberSequenceService;

/**
 * Manages the shop's customer accounts from the back office: search/list,
 * per-customer detail (order history), status (active/blocked), invoice
 * payment eligibility, GDPR export/delete, and creating sandbox "test
 * accounts". Direct port of admin/customers.php + admin/customer_view.php
 * + admin/customer_export.php - kept as one controller (rather than split
 * per legacy file) because all three pages act on the same customers
 * table and share the same list-with-stats query. Gated behind the
 * 'customers' capability (Super Admin only, see AdminAuth::CAPABILITIES)
 * since customer PII and GDPR actions are sensitive.
 */
final class CustomerAdminController extends AdminCrudController
{
    // Shared PDO connection - customers are read/written directly via
    // hand-written SQL rather than a Model class.
    private readonly \PDO $pdo;
    // Handles the actual GDPR export/delete logic (see class docblock of
    // Services\GdprService) - kept out of this controller so the "what data
    // counts as personal data" and "how to scrub an order vs a customer" rules
    // live in one place shared with any other caller.
    private readonly GdprService $gdpr;
    // Issues a test account's customer_number (Admin -> Numbering) - same
    // sequence real customers get, so the customer list never has to
    // special-case test accounts to show a number.
    private readonly NumberSequenceService $sequences;

    public function __construct(Request $request, Container $container)
    {
        parent::__construct($request, $container);
        $this->pdo = $container->make(\PDO::class);
        $this->gdpr = $container->make(GdprService::class);
        $this->sequences = $container->make(NumberSequenceService::class);
    }

    /** Lists customers (optionally filtered by a name/email search term), each annotated with their real order count, lifetime spend, and separately-counted test-order count. */
    public function index(Request $request): Response
    {
        $errors = [];
        $search = trim((string)$request->get('q', ''));
        // Three correlated subqueries per row: how many real orders, how much
        // they've actually paid (only 'paid' orders count), and how many test
        // orders (from is_test_account logins) - all excluding/isolating
        // is_test_order so test activity never inflates real figures, per
        // CLAUDE.md's Test accounts section.
        $sql = "SELECT c.*,
                       (SELECT COUNT(*) FROM orders o WHERE o.customer_id = c.id AND o.is_test_order = 0) AS order_count,
                       (SELECT COALESCE(SUM(total),0) FROM orders o WHERE o.customer_id = c.id AND o.payment_status = 'paid' AND o.is_test_order = 0) AS lifetime_value,
                       (SELECT COUNT(*) FROM orders o WHERE o.customer_id = c.id AND o.is_test_order = 1) AS test_order_count
                FROM customers c";
        $params = [];
        if ($search !== '') {
            $sql .= ' WHERE c.first_name LIKE ? OR c.last_name LIKE ? OR c.email LIKE ?';
            $params = ["%$search%", "%$search%", "%$search%"];
        }
        // Test accounts sort after real ones (is_test_account ASC puts 0/false
        // first), newest-created first within each group.
        $sql .= ' ORDER BY c.is_test_account, c.created_at DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $customers = $stmt->fetchAll();

        return $this->render('customers/index', compact('errors', 'search', 'customers') + ['pageTitle' => __('admin.customers')]);
    }

    /** Creates a new "test account" customer (is_test_account = 1) an admin can use to place orders that never touch real stock/revenue - see CLAUDE.md's Test accounts section. */
    public function createTestUser(Request $request): Response
    {
        // Blocks a forged account-creation submission (CSRF) - see Controller::requireCsrf().
        if ($csrfFailure = $this->requireCsrf()) {
            return $csrfFailure;
        }

        $errors = [];
        $firstName = trim((string)$request->post('first_name', ''));
        $lastName = trim((string)$request->post('last_name', ''));
        $email = filter_var($request->post('email', ''), FILTER_VALIDATE_EMAIL);
        $password = (string)$request->post('password', '');

        if (!$firstName || !$lastName) {
            $errors[] = __('admin.customers.name_required');
        }
        if (!$email) {
            $errors[] = __('validation.valid_email_required');
        }
        if (strlen($password) < 8) {
            $errors[] = __('validation.password_min_length');
        }

        if (!$errors) {
            // Only bother checking for a duplicate email if everything else was
            // valid - avoids an extra query when the form is already rejected.
            $stmt = $this->pdo->prepare('SELECT id FROM customers WHERE email = ?');
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $errors[] = __('validation.email_already_exists');
            }
        }

        if (!$errors) {
            // is_test_account hardcoded to 1 - this insert only ever creates test
            // accounts, never a real customer.
            $customerNumber = $this->sequences->next('customer');
            $stmt = $this->pdo->prepare(
                'INSERT INTO customers (customer_number, first_name, last_name, email, password_hash, is_test_account) VALUES (?, ?, ?, ?, ?, 1)'
            );
            $stmt->execute([$customerNumber, $firstName, $lastName, $email, password_hash($password, PASSWORD_DEFAULT)]);
            $this->flash('success', __('admin.customers.test_user_created', ['email' => $email]));
            return $this->redirect('/admin/customers');
        }

        // Validation failed - re-render the same list view (with the same stats
        // query as index()) plus the validation errors, so the admin sees what
        // went wrong without losing the customer list underneath the form.
        $search = '';
        $customers = $this->pdo->query(
            "SELECT c.*, (SELECT COUNT(*) FROM orders o WHERE o.customer_id = c.id AND o.is_test_order = 0) AS order_count,
                    (SELECT COALESCE(SUM(total),0) FROM orders o WHERE o.customer_id = c.id AND o.payment_status = 'paid' AND o.is_test_order = 0) AS lifetime_value,
                    (SELECT COUNT(*) FROM orders o WHERE o.customer_id = c.id AND o.is_test_order = 1) AS test_order_count
             FROM customers c ORDER BY c.is_test_account, c.created_at DESC"
        )->fetchAll();

        return $this->render('customers/index', compact('errors', 'search', 'customers') + ['pageTitle' => __('admin.customers')]);
    }

    /** Shows one customer's full detail page: their profile plus every order they've placed (real and test). */
    public function show(Request $request): Response
    {
        $id = (int)$request->routeParam('id', 0);
        $customer = $this->fetchCustomer($id);
        if (!$customer) {
            $this->flash('error', __('admin.customer_view.not_found'));
            return $this->redirect('/admin/customers');
        }

        $orderStmt = $this->pdo->prepare('SELECT * FROM orders WHERE customer_id = ? ORDER BY created_at DESC');
        $orderStmt->execute([$id]);
        $orders = $orderStmt->fetchAll();

        $pageTitle = $customer['first_name'] . ' ' . $customer['last_name'];
        return $this->render('customers/show', compact('customer', 'orders', 'pageTitle'));
    }

    /** Permanently deletes a customer, but only if it's flagged as a test account - guards against this route ever being used to delete a real customer (use gdprDelete() for that, which goes through the proper GDPR scrubbing). */
    public function deleteTestAccount(Request $request): Response
    {
        // Blocks a forged delete submission (CSRF) - see Controller::requireCsrf().
        if ($csrfFailure = $this->requireCsrf()) {
            return $csrfFailure;
        }
        $id = (int)$request->routeParam('id', 0);
        // AND is_test_account = 1 in the WHERE clause is the actual safety net -
        // even if a wrong/tampered id were submitted, this can never delete a
        // real customer row, only a test one.
        $this->pdo->prepare('DELETE FROM customers WHERE id = ? AND is_test_account = 1')->execute([$id]);
        $this->flash('success', __('admin.customer_view.test_user_deleted'));
        return $this->redirect('/admin/customers');
    }

    /** Runs the GDPR "right to erasure" deletion for one customer - hard-deletes their account/addresses and scrubs personal data off their orders while preserving order totals/line items for accounting. */
    public function gdprDelete(Request $request): Response
    {
        // Blocks a forged deletion submission (CSRF) - especially important
        // here since this is an irreversible data-erasure action.
        if ($csrfFailure = $this->requireCsrf()) {
            return $csrfFailure;
        }
        $id = (int)$request->routeParam('id', 0);
        $this->gdpr->deleteCustomer($id);
        $this->flash('success', __('admin.customer_view.gdpr_deleted'));
        return $this->redirect('/admin/customers');
    }

    /** Toggles whether this customer is allowed to choose "pay on invoice" at checkout. */
    public function savePayment(Request $request): Response
    {
        // Blocks a forged submission (CSRF) - see Controller::requireCsrf().
        if ($csrfFailure = $this->requireCsrf()) {
            return $csrfFailure;
        }
        $id = (int)$request->routeParam('id', 0);
        $canPayOnInvoice = $request->post('can_pay_on_invoice') ? 1 : 0;
        $this->pdo->prepare('UPDATE customers SET can_pay_on_invoice = ? WHERE id = ?')->execute([$canPayOnInvoice, $id]);
        $this->flash('success', __('admin.customer_view.flash_updated'));
        return $this->redirect('/admin/customers/' . $id);
    }

    /** Sets a customer's account status to active or blocked (e.g. to stop a fraudulent/abusive customer from ordering or logging in again). */
    public function saveStatus(Request $request): Response
    {
        // Blocks a forged submission (CSRF) - see Controller::requireCsrf().
        if ($csrfFailure = $this->requireCsrf()) {
            return $csrfFailure;
        }
        $id = (int)$request->routeParam('id', 0);
        // Whitelist check: only 'active'/'blocked' are accepted, defaulting to
        // 'active' for anything else submitted.
        $status = in_array($request->post('status', ''), ['active', 'blocked'], true) ? $request->post('status') : 'active';
        $this->pdo->prepare('UPDATE customers SET status = ? WHERE id = ?')->execute([$status, $id]);
        $this->flash('success', __('admin.customer_view.flash_updated'));
        return $this->redirect('/admin/customers/' . $id);
    }

    /** Downloads a GDPR "right to data portability" export - everything the shop holds about one customer, as a pretty-printed JSON file. */
    public function export(Request $request): Response
    {
        $id = (int)$request->routeParam('id', 0);
        $data = $this->gdpr->exportData($id);

        if (!$data) {
            $this->flash('error', __('admin.customer_view.not_found'));
            return $this->redirect('/admin/customers');
        }

        // JSON_UNESCAPED_SLASHES/UNICODE keep the output human-readable (no
        // \/ escaping, real UTF-8 characters instead of \uXXXX) since this is
        // meant to be inspected by a person, not just machine-parsed.
        // Content-Disposition: attachment forces a file download with a
        // per-customer, per-date filename rather than displaying the JSON inline.
        return Response::html((string)json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="customer-' . $id . '-data-' . date('Y-m-d') . '.json"');
    }

    /** Looks up one customer by id, or null if it doesn't exist. */
    private function fetchCustomer(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM customers WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }
}
