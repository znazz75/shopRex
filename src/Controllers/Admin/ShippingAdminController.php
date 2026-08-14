<?php

namespace ShopRex\Controllers\Admin;

use ShopRex\Core\Container;
use ShopRex\Core\Request;
use ShopRex\Core\Response;

/** Direct port of admin/shipping.php. */
final class ShippingAdminController extends AdminCrudController
{
    private readonly \PDO $pdo;

    public function __construct(Request $request, Container $container)
    {
        parent::__construct($request, $container);
        $this->pdo = $container->make(\PDO::class);
    }

    public function index(Request $request): Response
    {
        $errors = [];
        $editId = $request->get('edit') !== null ? (int)$request->get('edit') : null;
        $editMethod = null;
        $editTiers = [];
        if ($editId) {
            $stmt = $this->pdo->prepare('SELECT * FROM shipping_methods WHERE id = ?');
            $stmt->execute([$editId]);
            $editMethod = $stmt->fetch();
            if (!$editMethod) {
                $this->flash('error', __('admin.shipping.not_found'));
                return $this->redirect('/admin/shipping');
            }
            $tierStmt = $this->pdo->prepare('SELECT * FROM shipping_weight_tiers WHERE shipping_method_id = ? ORDER BY up_to_weight_kg ASC');
            $tierStmt->execute([$editId]);
            $editTiers = $tierStmt->fetchAll();
        }
        if (empty($editTiers)) {
            $editTiers = [['up_to_weight_kg' => '', 'price' => '']];
        }

        $methods = $this->methodsWithTierCounts();
        return $this->render('shipping/index', [...compact('errors', 'editMethod', 'editTiers', 'methods'), 'pageTitle' => __('admin.shipping')]);
    }

    public function save(Request $request): Response
    {
        if ($csrfFailure = $this->requireCsrf()) {
            return $csrfFailure;
        }

        if ($request->post('delete_id') !== null) {
            $this->pdo->prepare('DELETE FROM shipping_methods WHERE id = ?')->execute([(int)$request->post('delete_id')]);
            $this->flash('success', __('admin.shipping.flash_deleted'));
            return $this->redirect('/admin/shipping');
        }

        $errors = [];
        $id = $request->post('id') !== '' && $request->post('id') !== null ? (int)$request->post('id') : null;
        $name = trim((string)$request->post('name', ''));
        $isActive = $request->post('is_active') ? 1 : 0;
        $sortOrder = (int)$request->post('sort_order', 0);
        $freeMinValue = trim((string)$request->post('free_shipping_min_order_value', '')) !== '' ? (float)$request->post('free_shipping_min_order_value') : null;
        $freeMinQty = trim((string)$request->post('free_shipping_min_quantity', '')) !== '' ? max(1, (int)$request->post('free_shipping_min_quantity')) : null;
        $extraStepKg = trim((string)$request->post('extra_weight_step_kg', '')) !== '' ? (float)$request->post('extra_weight_step_kg') : null;
        $extraStepPrice = trim((string)$request->post('extra_weight_step_price', '')) !== '' ? (float)$request->post('extra_weight_step_price') : null;

        $tierUpTo = (array)$request->post('tier_up_to', []);
        $tierPrice = (array)$request->post('tier_price', []);
        $tiers = [];
        foreach ($tierUpTo as $i => $upTo) {
            $upTo = trim($upTo);
            $price = trim($tierPrice[$i] ?? '');
            if ($upTo === '' || $price === '') {
                continue;
            }
            $tiers[] = ['up_to_weight_kg' => (float)$upTo, 'price' => (float)$price];
        }
        usort($tiers, fn ($a, $b) => $a['up_to_weight_kg'] <=> $b['up_to_weight_kg']);

        if ($name === '') {
            $errors[] = __('admin.shipping.name_required');
        }
        if (empty($tiers)) {
            $errors[] = __('admin.shipping.tier_required');
        }
        if (($extraStepKg !== null) !== ($extraStepPrice !== null)) {
            $errors[] = __('admin.shipping.extra_step_pair_required');
        }
        if ($extraStepKg !== null && $extraStepKg <= 0) {
            $errors[] = __('admin.shipping.extra_step_kg_positive');
        }

        if (!$errors) {
            $this->pdo->beginTransaction();
            try {
                if ($id) {
                    $stmt = $this->pdo->prepare(
                        'UPDATE shipping_methods SET name=?, is_active=?, sort_order=?, free_shipping_min_order_value=?, free_shipping_min_quantity=?, extra_weight_step_kg=?, extra_weight_step_price=? WHERE id=?'
                    );
                    $stmt->execute([$name, $isActive, $sortOrder, $freeMinValue, $freeMinQty, $extraStepKg, $extraStepPrice, $id]);
                    $methodId = $id;
                    $this->pdo->prepare('DELETE FROM shipping_weight_tiers WHERE shipping_method_id = ?')->execute([$methodId]);
                    $this->flash('success', __('admin.shipping.flash_updated'));
                } else {
                    $stmt = $this->pdo->prepare(
                        'INSERT INTO shipping_methods (name, is_active, sort_order, free_shipping_min_order_value, free_shipping_min_quantity, extra_weight_step_kg, extra_weight_step_price) VALUES (?,?,?,?,?,?,?)'
                    );
                    $stmt->execute([$name, $isActive, $sortOrder, $freeMinValue, $freeMinQty, $extraStepKg, $extraStepPrice]);
                    $methodId = (int)$this->pdo->lastInsertId();
                    $this->flash('success', __('admin.shipping.flash_added'));
                }
                $tierStmt = $this->pdo->prepare('INSERT INTO shipping_weight_tiers (shipping_method_id, up_to_weight_kg, price, sort_order) VALUES (?,?,?,?)');
                foreach ($tiers as $i => $tier) {
                    $tierStmt->execute([$methodId, $tier['up_to_weight_kg'], $tier['price'], $i]);
                }
                $this->pdo->commit();
                return $this->redirect('/admin/shipping');
            } catch (\Throwable $e) {
                $this->pdo->rollBack();
                $errors[] = __('admin.shipping.save_error', ['message' => $e->getMessage()]);
            }
        }

        $editMethod = [
            'id' => $id, 'name' => $name, 'is_active' => $isActive, 'sort_order' => $sortOrder,
            'free_shipping_min_order_value' => $freeMinValue, 'free_shipping_min_quantity' => $freeMinQty,
            'extra_weight_step_kg' => $extraStepKg, 'extra_weight_step_price' => $extraStepPrice,
        ];
        $editTiers = array_map(fn ($t) => ['up_to_weight_kg' => $t['up_to_weight_kg'], 'price' => $t['price']], $tiers);
        if (empty($editTiers)) {
            $editTiers = [['up_to_weight_kg' => '', 'price' => '']];
        }
        $methods = $this->methodsWithTierCounts();
        return $this->render('shipping/index', [...compact('errors', 'editMethod', 'editTiers', 'methods'), 'pageTitle' => __('admin.shipping')]);
    }

    private function methodsWithTierCounts(): array
    {
        $methods = $this->pdo->query('SELECT * FROM shipping_methods ORDER BY sort_order, id')->fetchAll();
        foreach ($methods as &$m) {
            $tierCountStmt = $this->pdo->prepare('SELECT COUNT(*) FROM shipping_weight_tiers WHERE shipping_method_id = ?');
            $tierCountStmt->execute([$m['id']]);
            $m['tier_count'] = (int)$tierCountStmt->fetchColumn();
        }
        unset($m);
        return $methods;
    }
}
