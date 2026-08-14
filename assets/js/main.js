// Small progressive-enhancement helpers for the storefront (jQuery is
// loaded globally in includes/footer.php).
$(function () {
  // Confirm before removing a cart line item / other destructive actions.
  $('[data-confirm]').on('submit', function (e) {
    if (!confirm($(this).data('confirm'))) {
      e.preventDefault();
    }
  });

  // Toggle bank-transfer/invoice details visibility based on selected
  // payment method (checkout.php) - each detail box's id tells us which
  // payment_method value it belongs to via data-payment-method.
  var $paymentRadios = $('input[name="payment_method"]');
  var $paymentDetailBoxes = $('[data-payment-method]');
  if ($paymentRadios.length && $paymentDetailBoxes.length) {
    var sync = function () {
      var selected = $('input[name="payment_method"]:checked').val();
      $paymentDetailBoxes.each(function () {
        $(this).toggleClass('d-none', $(this).data('payment-method') !== selected);
      });
    };
    $paymentRadios.on('change', sync);
    sync();
  }

  // Product gallery: clicking a thumbnail moves the Bootstrap carousel to
  // that slide and highlights the active thumbnail (see product.php).
  var $carousel = $('#productGallery');
  if ($carousel.length) {
    var bsCarousel = bootstrap.Carousel.getOrCreateInstance($carousel[0], { ride: false });
    $('.gallery-thumb').on('click', function () {
      bsCarousel.to($(this).data('slide-index'));
    });
    $carousel.on('slid.bs.carousel', function (e) {
      $('.gallery-thumb').removeClass('active').eq(e.to).addClass('active');
    });
  }
});
