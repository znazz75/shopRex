<?php

namespace ShopRex\Controllers\Admin;

use ShopRex\Core\Container;
use ShopRex\Core\Request;
use ShopRex\Core\Response;

/**
 * Direct port of admin/tax_rates.php. Manages the tax rates (e.g. "Standard
 * 19%", "Reduced 7%") a product can be assigned, one of which is always
 * marked the store-wide default. Exists separately from the generic
 * AdminCrudController shape mainly because of the "always exactly one
 * default, and never zero rates total" invariants save()/index() enforce
 * below - a plain single-table CRUD wouldn't have anywhere natural to put
 * that logic.
 */
final class TaxRateAdminController extends AdminCrudController
{
    private readonly \PDO $pdo;

    public function __construct(Request $request, Container $container)
    {
        parent::__construct($request, $container);
        $this->pdo = $container->make(\PDO::class);
    }

    /** GET /admin/tax-rates - lists every tax rate (with how many products use each) and, if ?edit=id is set, loads that rate into the edit form. */
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

    /** Handles delete plus create/update for a tax rate, all posted to the same route - also enforces "at least one rate must always exist" and "exactly one rate is the default" (see the inline comments below). */
    public function save(Request $request): Response
    {
        if ($csrfFailure = $this->requireCsrf()) {
            return $csrfFailure;
        }

        if ($request->post('delete_id') !== null) {
            $deleteId = (int)$request->post('delete_id');
            // Every product references a tax_rate_id, so the system must
            // always have at least one rate to fall back to - deleting the
            // last remaining rate is blocked outright rather than allowed
            // and leaving products pointing at nothing.
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
            // Transaction: clearing the old default, writing the new/updated
            // row, and (if needed) re-picking a default all need to succeed
            // together, or none of them should apply - otherwise a failure
            // partway through could leave the table with zero default rates.
            $this->pdo->beginTransaction();
            try {
                // Only one rate can be the default at a time - if this
                // save is marking a rate as default, every other rate's
                // flag is cleared first so there's never more than one.
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
                // Safety net: if this save just UNset the only default
                // (unchecked the box on the rate that used to be default,
                // without checking it on any other), the system would be
                // left with zero default rates - this picks the
                // lowest-ID rate as the new default so that never happens.
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

    /** Every tax rate, highest percentage first, with a subquery count of how many products use each rate - shown in the list so an admin can see at a glance whether a rate is "safe" to delete. */
    private function allRates(): array
    {
        return $this->pdo->query('
            SELECT tr.*, (SELECT COUNT(*) FROM products p WHERE p.tax_rate_id = tr.id) AS product_count
            FROM tax_rates tr ORDER BY tr.rate DESC
        ')->fetchAll();
    }
}
