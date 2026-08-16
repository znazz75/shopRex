<?php
/**
 * Storefront RMA (warranty/defect claim) page - one card per order item,
 * each showing any existing ticket(s) for it plus (if still eligible) a
 * form to open a new claim. Rendered by Controllers\Storefront\RmaController::show()
 * for a logged-in customer's own order at /account/orders/{orderNumber}/rma.
 * This file is just the body; Core\Renderer::render() wraps it with the
 * theme's header.php/footer.php.
 *
 * Eligibility (statutory vs. manufacturer warranty) is computed server-side
 * in RmaController::itemsWithEligibility() from the product's configured
 * warranty length and the order date (RmaTicket::isEligible()) - this view
 * only ever reflects that decision, it doesn't recompute it. submit() (the
 * POST handler for the form below) re-checks eligibility again itself, so
 * nothing here needs to be trusted for security.
 *
 * @var \ShopRex\Models\Order $order         The order these items belong to.
 * @var array $items          One row per order_item, each with:
 *                             product_name, option_summary (e.g. "Size: L",
 *                             blank if the product has no options), id,
 *                             statutory_eligible / manufacturer_eligible
 *                             (bools - whether a new claim of that type can
 *                             still be opened for this item today).
 * @var array $ticketsByItem  Map of order_item_id => array of already-
 *                             submitted RmaTicket objects for that item
 *                             (each carrying a ->status like "pending" /
 *                             "approved" / "rejected").
 * New in v2.00.
 */
?>
<div class="row justify-content-center">
  <div class="col-md-9">
    <h1 class="h3 mb-3"><?= e(__('rma.title')) ?></h1>
    <p class="text-secondary"><?= e(__('order.number')) ?>: <strong><?= e($order->orderNumber) ?></strong></p>

    <?php // One card per line item on the order - every item gets a card
          // regardless of eligibility, so the customer can see why an item
          // has no claim form (either already claimed, or no longer eligible). ?>
    <?php foreach ($items as $item): ?>
      <div class="card mb-3">
        <div class="card-body">
          <h2 class="h6"><?= e($item['product_name']) ?> <?= $item['option_summary'] ? '(' . e($item['option_summary']) . ')' : '' ?></h2>

          <?php // Show a status banner for every ticket already opened on this
                // item (a customer could, in theory, have more than one over
                // time) - the human-readable status turns "in_review" into
                // "In Review" for display. ?>
          <?php foreach ($ticketsByItem[$item['id']] ?? [] as $ticket): ?>
            <div class="alert alert-info py-2 small">
              <?php /* rmaNumber is null for a ticket filed before Admin -> Numbering existed - just skip the "Ticket #..." line for those rather than showing a blank number. */ ?>
              <?php if ($ticket->rmaNumber): ?><strong><?= e(__('rma.ticket_number', ['number' => $ticket->rmaNumber])) ?></strong> - <?php endif; ?>
              <?= e(__('rma.existing_ticket', ['status' => ucwords(str_replace('_', ' ', $ticket->status))])) ?>
            </div>
          <?php endforeach; ?>

          <?php if (!$item['statutory_eligible'] && !$item['manufacturer_eligible']): ?>
            <?php // Neither warranty type still applies (window expired, or
                  // the product record was deleted) - no form, just a note. ?>
            <p class="text-secondary small mb-0"><?= e(__('rma.not_eligible')) ?></p>
          <?php else: ?>
            <form method="post" action="<?= rtrim(SITE_URL, '/') ?>/account/orders/<?= urlencode($order->orderNumber) ?>/rma" enctype="multipart/form-data">
              <?= csrfField() ?>
              <input type="hidden" name="order_item_id" value="<?= (int)$item['id'] ?>">
              <div class="mb-2">
                <label class="form-label"><?= e(__('rma.claim_type')) ?></label><br>
                <?php // Only offer the radio options that are actually still
                      // eligible; whichever one is offered first is pre-checked
                      // so a single-eligibility item needs no extra click. ?>
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
                <?php // Up to MAX_ATTACHMENTS (5, enforced server-side in
                      // RmaController::submit()) - the `multiple` attribute
                      // just lets the browser's file picker select several at
                      // once, it's not itself a limit. ?>
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
