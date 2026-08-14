<?php

namespace ShopRex\Controllers\Storefront;

use ShopRex\Core\Auth\CustomerAuth;
use ShopRex\Core\Container;
use ShopRex\Core\Controller;
use ShopRex\Core\Request;
use ShopRex\Core\Response;
use ShopRex\Services\GdprService;

/** Direct port of account.php + account_export.php + account_delete.php. */
final class AccountController extends Controller
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
        if ($guard = $this->requireCustomerLogin()) {
            return $guard;
        }
        $customer = CustomerAuth::current();

        $stmt = $this->pdo->prepare('SELECT * FROM orders WHERE customer_id = ? ORDER BY created_at DESC');
        $stmt->execute([$customer['id']]);
        $orders = $stmt->fetchAll();

        $invoicesByOrder = [];
        try {
            $invStmt = $this->pdo->prepare('SELECT order_id FROM invoices WHERE order_id IN (SELECT id FROM orders WHERE customer_id = ?)');
            $invStmt->execute([$customer['id']]);
            $invoicesByOrder = array_flip(array_column($invStmt->fetchAll(), 'order_id'));
        } catch (\Throwable $e) {
            // invoices table not present yet - just hide the download links.
        }

        $pageTitle = __('account.title');
        return $this->render('account/index', compact('customer', 'orders', 'invoicesByOrder', 'pageTitle'));
    }

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

    public function confirmDelete(Request $request): Response
    {
        if ($guard = $this->requireCustomerLogin()) {
            return $guard;
        }
        return $this->render('account/delete_confirm', ['error' => null, 'pageTitle' => __('account.delete_confirm_title')]);
    }

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

        if (!password_verify($password, $customer['password_hash'])) {
            return $this->render('account/delete_confirm', [
                'error' => __('account.delete_wrong_password'), 'pageTitle' => __('account.delete_confirm_title'),
            ]);
        }

        $this->gdpr->deleteCustomer($customer['id']);
        $this->request->session()->remove('customer_id');
        $this->request->session()->regenerate();
        CustomerAuth::forget();
        $this->flash('success', __('account.delete_success'));
        return $this->redirect('/');
    }
}
