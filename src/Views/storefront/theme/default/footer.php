
</main>
<?php
use ShopRex\Support\StorefrontMenuRenderer;

$footerMenu = getMenuTree('footer');
$legalDocuments = getLegalDocuments();
$shopName = getSetting('shop_name', SITE_NAME);
$shopEmail = getSetting('shop_email', ADMIN_EMAIL);
?>
<footer class="bg-dark text-white-50 pt-5 pb-4 mt-5">
  <div class="container">
    <div class="row gy-4">
      <div class="col-lg-4">
        <h5 class="text-white"><?= e($shopName) ?></h5>
        <p class="small"><?= e(__('footer.tagline')) ?></p>
        <?php if ($shopEmail): ?><p class="small mb-0"><i class="bi bi-envelope me-1"></i><a class="link-light" href="mailto:<?= e($shopEmail) ?>"><?= e($shopEmail) ?></a></p><?php endif; ?>
      </div>
      <div class="col-lg-4">
        <h6 class="text-white"><?= e(__('footer.links')) ?></h6>
        <ul class="list-unstyled">
          <?php StorefrontMenuRenderer::renderFooter($footerMenu); ?>
          <?php StorefrontMenuRenderer::renderFooterLegalDocuments($legalDocuments); ?>
        </ul>
      </div>
      <div class="col-lg-4">
        <h6 class="text-white"><?= e(__('footer.we_accept')) ?></h6>
        <p class="small mb-1"><i class="bi bi-paypal me-2"></i><?= e(__('checkout.paypal')) ?></p>
        <p class="small mb-1"><i class="bi bi-credit-card me-2"></i><?= e(__('checkout.credit_card')) ?></p>
        <p class="small mb-1"><i class="bi bi-bank me-2"></i><?= e(__('checkout.bank_transfer')) ?></p>
      </div>
    </div>
    <hr class="border-secondary">
    <p class="small text-center mb-0">&copy; <?= date('Y') ?> <?= e($shopName) ?>. <?= e(__('footer.rights')) ?></p>
  </div>
</footer>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= rtrim(SITE_URL, '/') ?>/assets/js/main.js"></script>
</body>
</html>
