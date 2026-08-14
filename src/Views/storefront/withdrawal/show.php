<?php
/**
 * @var \ShopRex\Models\Order $order
 * @var array $items
 * @var \ShopRex\Models\WithdrawalRequest|null $existing
 * @var \DateTimeImmutable $deadline
 * @var bool $pastDeadline
 * New in v2.00.
 */
?>
<div class="row justify-content-center">
  <div class="col-md-8">
    <h1 class="h3 mb-3"><?= e(__('withdrawal.title')) ?></h1>
    <p class="text-secondary"><?= e(__('order.number')) ?>: <strong><?= e($order->orderNumber) ?></strong></p>

    <?php if ($existing): ?>
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
