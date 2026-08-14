<?php
/**
 * @var \ShopRex\Models\Order $order
 * @var array $items
 * @var array $ticketsByItem
 * New in v2.00.
 */
?>
<div class="row justify-content-center">
  <div class="col-md-9">
    <h1 class="h3 mb-3"><?= e(__('rma.title')) ?></h1>
    <p class="text-secondary"><?= e(__('order.number')) ?>: <strong><?= e($order->orderNumber) ?></strong></p>

    <?php foreach ($items as $item): ?>
      <div class="card mb-3">
        <div class="card-body">
          <h2 class="h6"><?= e($item['product_name']) ?> <?= $item['option_summary'] ? '(' . e($item['option_summary']) . ')' : '' ?></h2>

          <?php foreach ($ticketsByItem[$item['id']] ?? [] as $ticket): ?>
            <div class="alert alert-info py-2 small">
              <?= e(__('rma.existing_ticket', ['status' => ucwords(str_replace('_', ' ', $ticket->status))])) ?>
            </div>
          <?php endforeach; ?>

          <?php if (!$item['statutory_eligible'] && !$item['manufacturer_eligible']): ?>
            <p class="text-secondary small mb-0"><?= e(__('rma.not_eligible')) ?></p>
          <?php else: ?>
            <form method="post" action="<?= rtrim(SITE_URL, '/') ?>/account/orders/<?= urlencode($order->orderNumber) ?>/rma" enctype="multipart/form-data">
              <?= csrfField() ?>
              <input type="hidden" name="order_item_id" value="<?= (int)$item['id'] ?>">
              <div class="mb-2">
                <label class="form-label"><?= e(__('rma.claim_type')) ?></label><br>
                <?php if ($item['statutory_eligible']): ?>
                  <label class="me-3"><input type="radio" name="warranty_claim_type" value="statutory" checked> <?= e(__('rma.statutory_warranty')) ?></label>
                <?php endif; ?>
                <?php if ($item['manufacturer_eligible']): ?>
                  <label><input type="radio" name="warranty_claim_type" value="manufacturer" <?= !$item['statutory_eligible'] ? 'checked' : '' ?>> <?= e(__('rma.manufacturer_warranty')) ?></label>
                <?php endif; ?>
              </div>
              <div class="mb-2">
                <label class="form-label" for="defect-<?= (int)$item['id'] ?>"><?= e(__('rma.defect_description')) ?></label>
                <textarea class="form-control" id="defect-<?= (int)$item['id'] ?>" name="defect_description" rows="3" required></textarea>
              </div>
              <div class="mb-2">
                <label class="form-label" for="photos-<?= (int)$item['id'] ?>"><?= e(__('rma.photos')) ?></label>
                <input class="form-control" type="file" id="photos-<?= (int)$item['id'] ?>" name="photos[]" accept="image/*" multiple>
              </div>
              <button class="btn btn-outline-danger btn-sm" type="submit"><?= e(__('rma.submit')) ?></button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
