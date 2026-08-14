<?php

namespace ShopRex\Controllers\Admin;

use ShopRex\Core\Container;
use ShopRex\Core\Request;
use ShopRex\Core\Response;
use ShopRex\Services\GdprService;

/** Direct port of admin/customers.php + admin/customer_view.php + admin/customer_export.php. */
final class CustomerAdminController extends AdminCrudController
{
    private readonly \PDO $pdo;
    private readonly GdprService $gdpr;

    public function __construct(Request $request, Container $container)
    {
        parent::__construct($request, $container);
        $this->pdo = $container->make(\PDO::class);
        $this->gdpr = $container->make(GdprService::class);
    }

    public function index(Request $request): Response
    {
        $errors = [];
        $search = trim((string)$request->get('q', ''));
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
        $sql .= ' ORDER BY c.is_test_account, c.created_at DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $customers = $stmt->fetchAll();

        return $this->render('customers/index', compact('errors', 'search', 'customers') + ['pageTitle' => __('admin.customers')]);
    }

    public function createTestUser(Request $request): Response
    {
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
            $stmt = $this->pdo->prepare('SELECT id FROM customers WHERE email = ?');
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $errors[] = __('validation.email_already_exists');
            }
        }

        if (!$errors) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO customers (first_name, last_name, email, password_hash, is_test_account) VALUES (?, ?, ?, ?, 1)'
            );
            $stmt->execute([$firstName, $lastName, $email, password_hash($password, PASSWORD_DEFAULT)]);
            $this->flash('success', __('admin.customers.test_user_created', ['email' => $email]));
            return $this->redirect('/admin/customers');
        }

        $search = '';
        $customers = $this->pdo->query(
            "SELECT c.*, (SELECT COUNT(*) FROM orders o WHERE o.customer_id = c.id AND o.is_test_order = 0) AS order_count,
                    (SELECT COALESCE(SUM(total),0) FROM orders o WHERE o.customer_id = c.id AND o.payment_status = 'paid' AND o.is_test_order = 0) AS lifetime_value,
                    (SELECT COUNT(*) FROM orders o WHERE o.customer_id = c.id AND o.is_test_order = 1) AS test_order_count
             FROM customers c ORDER BY c.is_test_account, c.created_at DESC"
        )->fetchAll();

        return $this->render('customers/index', compact('errors', 'search', 'customers') + ['pageTitle' => __('admin.customers')]);
    }

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

    public function deleteTestAccount(Request $request): Response
    {
        if ($csrfFailure = $this->requireCsrf()) {
            return $csrfFailure;
        }
        $id = (int)$request->routeParam('id', 0);
        $this->pdo->prepare('DELETE FROM customers WHERE id = ? AND is_test_account = 1')->execute([$id]);
        $this->flash('success', __('admin.customer_view.test_user_deleted'));
        return $this->redirect('/admin/customers');
    }

    public function gdprDelete(Request $request): Response
    {
        if ($csrfFailure = $this->requireCsrf()) {
            return $csrfFailure;
        }
        $id = (int)$request->routeParam('id', 0);
        $this->gdpr->deleteCustomer($id);
        $this->flash('success', __('admin.customer_view.gdpr_deleted'));
        return $this->redirect('/admin/customers');
    }

    public function savePayment(Request $request): Response
    {
        if ($csrfFailure = $this->requireCsrf()) {
            return $csrfFailure;
        }
        $id = (int)$request->routeParam('id', 0);
        $canPayOnInvoice = $request->post('can_pay_on_invoice') ? 1 : 0;
        $this->pdo->prepare('UPDATE customers SET can_pay_on_invoice = ? WHERE id = ?')->execute([$canPayOnInvoice, $id]);
        $this->flash('success', __('admin.customer_view.flash_updated'));
        return $this->redirect('/admin/customers/' . $id);
    }

    public function saveStatus(Request $request): Response
    {
        if ($csrfFailure = $this->requireCsrf()) {
            return $csrfFailure;
        }
        $id = (int)$request->routeParam('id', 0);
        $status = in_array($request->post('status', ''), ['active', 'blocked'], true) ? $request->post('status') : 'active';
        $this->pdo->prepare('UPDATE customers SET status = ? WHERE id = ?')->execute([$status, $id]);
        $this->flash('success', __('admin.customer_view.flash_updated'));
        return $this->redirect('/admin/customers/' . $id);
    }

    public function export(Request $request): Response
    {
        $id = (int)$request->routeParam('id', 0);
        $data = $this->gdpr->exportData($id);

        if (!$data) {
            $this->flash('error', __('admin.customer_view.not_found'));
            return $this->redirect('/admin/customers');
        }

        return Response::html((string)json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="customer-' . $id . '-data-' . date('Y-m-d') . '.json"');
    }

    private function fetchCustomer(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM customers WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }
}
