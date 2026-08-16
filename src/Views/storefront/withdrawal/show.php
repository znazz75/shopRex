<?php
/**
 * Storefront right-of-withdrawal page for a single order - lets a customer
 * either see the status of a withdrawal they already filed, or (if still
 * within the deadline and none filed yet) select which items to withdraw
 * and submit a new request. Rendered by
 * Controllers\Storefront\WithdrawalController::show() at
 * /account/orders/{orderNumber}/withdrawal. This file is just the body;
 * Core\Renderer::render() wraps it with the theme's header.php/footer.php.
 *
 * The three states below (existing request / past deadline / open form)
 * are mutually exclusive and computed entirely server-side - the view just
 * branches on them, it doesn't decide eligibility itself. submit() (the
 * POST handler for the form) re-derives the deadline and the eligible item
 * IDs again independently, so nothing rendered here needs to be trusted.
 *
 * @var \ShopRex\Models\Order $order                        The order being withdrawn from.
 * @var array $items                       One row per order_item, each with
 *                                          product_name, option_summary,
 *                                          id, and is_hygiene_product (a
 *                                          product flagged as hygiene-
 *                                          sensitive, e.g. underwear/
 *                                          earrings, is legally excluded
 *                                          from the right of withdrawal).
 * @var \ShopRex\Models\WithdrawalRequest|null $existing     A withdrawal
 *                                          request already filed for this
 *                                          order, if any - null means none
 *                                          filed yet.
 * @var \DateTimeImmutable $deadline       The last moment a withdrawal can
 *                                          still be filed for this order
 *                                          (WithdrawalRequest::calculateDeadline()
 *                                          - a fixed window from delivery/
 *                                          order date per statute/setting).
 * @var bool $pastDeadline                 True if "now" is already after
 *                                          $deadline - shown as a warning
 *                                          instead of the selection form.
 * New in v2.00.
 */
?>
<div class="row justify-content-center">
  <div class="col-md-8">
    <h1 class="h3 mb-3"><?= e(__('withdrawal.title')) ?></h1>
    <p class="text-secondary"><?= e(__('order.number')) ?>: <strong><?= e($order->orderNumber) ?></strong></p>

    <?php // Three-way branch: (1) a request already exists for this order -
          // just show its status/reason, no form; (2) no request yet but the
          // deadline has passed - show a warning instead of a form; (3)
          // still open - show the selectable item list + reason form. ?>
    <?php if ($existing): ?>
      <?php /* withdrawalNumber is null for a request filed before Admin -> Numbering existed - just skip this line for those rather than showing a blank number. */ ?>
      <?php if ($existing->withdrawalNumber): ?>
        <p class="text-secondary small"><?= e(__('withdrawal.request_number', ['number' => $existing->withdrawalNumber])) ?></p>
      <?php endif; ?>
      <div class="alert alert-info">
        <?= e(__('withdrawal.status_label')) ?>:
        <span class="badge text-bg-secondary"><?= e(ucwords(str_replace('_', ' ', $existing->status))) ?></span>
      </div>
      <?php if ($existing->reason): ?><p><?= e(__('withdrawal.reason_label')) ?>: <?= e($existing->reason) ?></p><?php endif; ?>
    <?php elseif ($pastDeadline): ?>
      <div class="alert alert-warning"><?= e(__('withdrawal.past_deadline')) ?></div>
    <?php else: ?>
      <p class="text-secondary"><?= e(__('withdrawal.deadline_note', ['date' => e(formatLocalDate($deadline->format('Y-m-d H:i:s'))) ])) ?></p>

      <form method="post">
        <?= csrfField() ?>
        <div class="mb-3">
          <?php // Hygiene-flagged items get a disabled, unchecked checkbox
                // plus an explanatory note instead of a real form field, so
                // they simply won't be included in items[] on submit - the
                // controller enforces this exclusion again anyway. Everything
                // else defaults to checked (opt-out, not opt-in). ?>
          <?php foreach ($items as $item): ?>
            <div class="form-check mb-1">
              <?php if ($item['is_hygiene_product']): ?>
                <input class="form-check-input" type="checkbox" disabled>
                <label class="form-check-label text-secondary">
                  <?= e($item['product_name']) ?> <?= $item['option_summary'] ? '(' . e($item['option_summary']) . ')' : '' ?>
                  <br><small><?= e(__('withdrawal.hygiene_excluded')) ?></small>
                </label>
              <?php else: ?>
                <input class="form-check-input" type="checkbox" name="items[]" value="<?= (int)$item['id'] ?>" id="item-<?= (int)$item['id'] ?>" checked>
                <label class="form-check-label" for="item-<?= (int)$item['id'] ?>">
                  <?= e($item['product_name']) ?> <?= $item['option_summary'] ? '(' . e($item['option_summary']) . ')' : '' ?>
                </label>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="mb-3">
          <label class="form-label" for="reason"><?= e(__('withdrawal.reason_optional')) ?></label>
          <textarea class="form-control" id="reason" name="reason" rows="3"></textarea>
        </div>
        <button class="btn btn-danger" type="submit"><?= e(__('withdrawal.submit')) ?></button>
      </form>
    <?php endif; ?>

    <p class="mt-4"><a href="<?= rtrim(SITE_URL, '/') ?>/page/right-of-withdrawal"><?= e(__('withdrawal.read_policy')) ?></a></p>
  </div>
</div>
