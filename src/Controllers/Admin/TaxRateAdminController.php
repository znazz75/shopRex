<?php

namespace ShopRex\Controllers\Admin;

use ShopRex\Core\Container;
use ShopRex\Core\Request;
use ShopRex\Core\Response;
use ShopRex\Services\TaxCalculator;

/** Direct port of admin/tax_rates.php. */
final class TaxRateAdminController extends AdminCrudController
{
    private readonly \PDO $pdo;
    private readonly TaxCalculator $tax;

    public function __construct(Request $request, Container $container)
    {
        parent::__construct($request, $container);
        $this->pdo = $container->make(\PDO::class);
        $this->tax = $container->make(TaxCalculator::class);
    }

    public function index(Request $request): Response
    {
        $errors = [];
        $editId = $request->get('edit') !== null ? (int)$request->get('edit') : null;
        $editRate = null;
        if ($editId) {
            $stmt = $this->pdo->prepare('SELECT * FROM tax_rates WHERE id = ?');
            $stmt->execute([$editId]);
            $editRate = $stmt->fetch();
            if (!$editRate) {
                $this->flash('error', __('admin.tax_rates.not_found'));
                return $this->redirect('/admin/tax-rates');
            }
        }

        $rates = $this->allRates();
        return $this->render('tax_rates/index', [...compact('errors', 'editRate', 'rates'), 'pageTitle' => __('admin.tax_rates')]);
    }

    public function save(Request $request): Response
    {
        if ($csrfFailure = $this->requireCsrf()) {
            return $csrfFailure;
        }

        if ($request->post('delete_id') !== null) {
            $deleteId = (int)$request->post('delete_id');
            $count = (int)$this->pdo->query('SELECT COUNT(*) FROM tax_rates')->fetchColumn();
            if ($count <= 1) {
                $this->flash('error', __('admin.tax_rates.at_least_one_required'));
            } else {
                $this->pdo->prepare('DELETE FROM tax_rates WHERE id = ?')->execute([$deleteId]);
                $this->flash('success', __('admin.tax_rates.flash_deleted'));
            }
            return $this->redirect('/admin/tax-rates');
        }

        $errors = [];
        $id = $request->post('id') !== '' && $request->post('id') !== null ? (int)$request->post('id') : null;
        $name = trim((string)$request->post('name', ''));
        $rate = (float)$request->post('rate', -1);
        $isDefault = (bool)$request->post('is_default');

        if ($name === '') {
            $errors[] = __('admin.categories.name_required');
        }
        if ($rate < 0 || $rate > 100) {
            $errors[] = __('admin.tax_rates.rate_range');
        }

        if (!$errors) {
            $this->pdo->beginTransaction();
            try {
                if ($isDefault) {
                    $this->pdo->exec('UPDATE tax_rates SET is_default = 0');
                }
                if ($id) {
                    $stmt = $this->pdo->prepare('UPDATE tax_rates SET name=?, rate=?, is_default=? WHERE id=?');
                    $stmt->execute([$name, $rate, $isDefault ? 1 : 0, $id]);
                } else {
                    $stmt = $this->pdo->prepare('INSERT INTO tax_rates (name, rate, is_default) VALUES (?, ?, ?)');
                    $stmt->execute([$name, $rate, $isDefault ? 1 : 0]);
                }
                if ((int)$this->pdo->query('SELECT COUNT(*) FROM tax_rates WHERE is_default = 1')->fetchColumn() === 0) {
                    $this->pdo->exec('UPDATE tax_rates SET is_default = 1 ORDER BY id LIMIT 1');
                }
                $this->pdo->commit();
                $this->flash('success', __('admin.tax_rates.flash_saved'));
                return $this->redirect('/admin/tax-rates');
            } catch (\PDOException $e) {
                $this->pdo->rollBack();
                $errors[] = __('admin.tax_rates.save_error', ['message' => $e->getMessage()]);
            }
        }

        $editRate = ['id' => $id, 'name' => $name, 'rate' => $rate, 'is_default' => $isDefault ? 1 : 0];
        $rates = $this->allRates();
        return $this->render('tax_rates/index', [...compact('errors', 'editRate', 'rates'), 'pageTitle' => __('admin.tax_rates')]);
    }

    private function allRates(): array
    {
        return $this->pdo->query('
            SELECT tr.*, (SELECT COUNT(*) FROM products p WHERE p.tax_rate_id = tr.id) AS product_count
            FROM tax_rates tr ORDER BY tr.rate DESC
        ')->fetchAll();
    }
}
