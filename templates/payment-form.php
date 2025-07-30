<div class="sberbank-payment-form">
    <p><?php _e('Ваш заказ оформлен. Для завершения оплаты нажмите кнопку ниже.', 'sberbank-payment-gateway'); ?></p>
    
    <p class="order-amount">
        <?php _e('Сумма к оплате:', 'sberbank-payment-gateway'); ?>
        <strong><?php echo wc_price($order->get_total()); ?></strong>
    </p>
    
    <a href="<?php echo esc_url($payment_url); ?>" class="button alt" id="sberbank-pay-button">
        <?php _e('Перейти к оплате', 'sberbank-payment-gateway'); ?>
    </a>
    
    <?php if ($gateway->auto_redirect): ?>
    <script>
        jQuery(document).ready(function($) {
            // Автоматический редирект через 5 секунд
            setTimeout(function() {
                window.location = '<?php echo esc_url($payment_url); ?>';
            }, 5000);
        });
    </script>
    <?php endif; ?>
</div>