jQuery(document).ready(function($) {
  $('#sberbank-mirpay-button').on('click', function(e) {
      e.preventDefault();
      
      var $form = $(this).closest('form');
      
      if (!$form.length) {
          $form = $(this).closest('.woocommerce-checkout');
      }
      
      if ($form.length) {
          $form.block({
              message: null,
              overlayCSS: {
                  background: '#fff',
                  opacity: 0.6
              }
          });
          
          // Добавляем скрытое поле для идентификации MirPay
          $form.append('<input type="hidden" name="sberbank_mirpay_payment" value="1">');
          
          // Отправляем форму
          $form.submit();
      }
  });
});
