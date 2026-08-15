<?php

namespace ShopRex\Controllers\Admin;

use ShopRex\Core\Container;
use ShopRex\Core\Request;
use ShopRex\Core\Response;

/**
 * Direct port of admin/shipping.php. Manages shipping methods (e.g. "Standard",
 * "Express") and each method's weight-based price tiers (e.g. "up to 2kg:
 * EUR 4.99, up to 5kg: EUR 7.99"), used by Services\ShippingCalculator at
 * checkout to price an order's shipping. Not a plain AdminCrudController-
 * style single-table CRUD because saving a method also replaces its whole
 * set of child weight tiers in the same request (see save() below).
 */
final class ShippingAdminController extends AdminCrudController
{
    /** Raw database handle for this controller's queries against `shipping_methods`/`shipping_weight_tiers`. */
    private readonly \PDO $pdo;

    public function __construct(Request $request, Container $container)
    {
        parent::__construct($request, $container);
        $this->pdo = $container->make(\PDO::class);
    }

    /** GET /admin/shipping - lists every shipping method (with its tier count) and, if ?edit=id is set, loads that method plus its weight tiers into the edit form. */
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
        // Always show at least one (blank) tier row in the form, whether
        // creating a brand-new method or editing one that somehow has no
        // tiers yet - gives the admin one empty row to start filling in
        // instead of an empty list with no obvious way to add the first row.
        if (empty($editTiers)) {
            $editTiers = [['up_to_weight_kg' => '', 'price' => '']];
        }

        $methods = $this->methodsWithTierCounts();
        return $this->render('shipping/index', [...compact('errors', 'editMethod', 'editTiers', 'methods'), 'pageTitle' => __('admin.shipping')]);
    }

    /**
     * Handles delete plus create/update-with-tiers for a shipping method,
     * all posted to the same route. Saving always replaces the method's
     * entire set of weight tiers (delete-then-reinsert inside a
     * transaction) rather than diffing old vs new rows - simpler to reason
     * about than matching up which tier row is "the same" one across an
     * edit, at the cost of tier IDs changing on every save (nothing else
     * references a tier by ID, so that's harmless here).
     */
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
        // Each of these "extra" fields is optional - an empty submission
        // is stored as NULL (feature disabled) rather than 0, which
        // Services\ShippingCalculator relies on to tell "no free-shipping
        // threshold configured" apart from "threshold is $0".
        $freeMinValue = trim((string)$request->post('free_shipping_min_order_value', '')) !== '' ? (float)$request->post('free_shipping_min_order_value') : null;
        $freeMinQty = trim((string)$request->post('free_shipping_min_quantity', '')) !== '' ? max(1, (int)$request->post('free_shipping_min_quantity')) : null;
        $extraStepKg = trim((string)$request->post('extra_weight_step_kg', '')) !== '' ? (float)$request->post('extra_weight_step_kg') : null;
        $extraStepPrice = trim((string)$request->post('extra_weight_step_price', '')) !== '' ? (float)$request->post('extra_weight_step_price') : null;

        // The form submits parallel arrays (tier_up_to[]/tier_price[] -
        // one entry per tier row) rather than nested objects, since plain
        // HTML forms can't post structured data - zipped back together
        // here by matching index. Rows where either half is blank are
        // silently dropped (an admin who leaves a spare blank row doesn't
        // get an error for it).
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
        // Tiers must be stored lightest-to-heaviest - ShippingCalculator
        // picks the first tier whose weight ceiling is >= the order's
        // weight, so an out-of-order list would pick the wrong price.
        usort($tiers, fn ($a, $b) => $a['up_to_weight_kg'] <=> $b['up_to_weight_kg']);

        if ($name === '') {
            $errors[] = __('admin.shipping.name_required');
        }
        if (empty($tiers)) {
            $errors[] = __('admin.shipping.tier_required');
        }
        // The two "extra weight step" fields are a pair - either both
        // filled in or both empty, never just one (a lone price with no
        // weight step, or vice versa, wouldn't mean anything).
        if (($extraStepKg !== null) !== ($extraStepPrice !== null)) {
            $errors[] = __('admin.shipping.extra_step_pair_required');
        }
        if ($extraStepKg !== null && $extraStepKg <= 0) {
            $errors[] = __('admin.shipping.extra_step_kg_positive');
        }

        if (!$errors) {
            // Transaction: the method row and its tier rows must both
            // succeed or both roll back together, so a mid-save failure
            // (e.g. a bad tier value) can never leave a method saved with
            // an inconsistent/partial set of tiers.
            $this->pdo->beginTransaction();
            try {
                if ($id) {
                    $stmt = $this->pdo->prepare(
                        'UPDATE shipping_methods SET name=?, is_active=?, sort_order=?, free_shipping_min_order_value=?, free_shipping_min_quantity=?, extra_weight_step_kg=?, extra_weight_step_price=? WHERE id=?'
                    );
                    $stmt->execute([$name, $isActive, $sortOrder, $freeMinValue, $freeMinQty, $extraStepKg, $extraStepPrice, $id]);
                    $methodId = $id;
                    // Delete-then-reinsert strategy for tiers (see this
                    // method's docblock) - every existing tier for this
                    // method is wiped before the new set is written below.
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

    /** Every shipping method plus how many weight tiers each has - the list page shows the tier count as a quick sanity check without needing to open each method's edit form. */
    private function methodsWithTierCounts(): array
    {
        $methods = $this->pdo->query('SELECT * FROM shipping_methods ORDER BY sort_order, id')->fetchAll();
        // One extra COUNT(*) query per method (N+1) rather than one JOIN -
        // fine here since the number of shipping methods on a real store
        // is always tiny, and it keeps this simple. `&$m` modifies each
        // row of $methods in place as the loop runs.
        foreach ($methods as &$m) {
            $tierCountStmt = $this->pdo->prepare('SELECT COUNT(*) FROM shipping_weight_tiers WHERE shipping_method_id = ?');
            $tierCountStmt->execute([$m['id']]);
            $m['tier_count'] = (int)$tierCountStmt->fetchColumn();
        }
        // Breaks the reference after the loop - without this, $m would
        // keep pointing at the last element, and any later `foreach` reusing
        // the name $m could silently overwrite that last array entry.
        unset($m);
        return $methods;
    }
}
