<div class="sberbank-payment-form">
    <div class="sberbank-payment-card">
        <div class="sberbank-payment-header">
            <h3><?php _e('Оплата заказа', 'sberbank-payment-gateway'); ?></h3>
            <div class="sberbank-logo">
                <img src="<?php echo SBERBANK_PAYMENT_GATEWAY_PLUGIN_URL . 'assets/images/sberbank-logo.png'; ?>" alt="Сбербанк">
            </div>
        </div>
        
        <div class="sberbank-payment-content">
            <p><?php _e('Ваш заказ оформлен. Для завершения оплаты нажмите кнопку ниже.', 'sberbank-payment-gateway'); ?></p>
            
            <div class="sberbank-order-details">
                <div class="sberbank-order-amount">
                    <span><?php _e('Сумма к оплате:', 'sberbank-payment-gateway'); ?></span>
                    <strong><?php echo wc_price($order->get_total()); ?></strong>
                </div>
                <div class="sberbank-order-number">
                    <span><?php _e('Номер заказа:', 'sberbank-payment-gateway'); ?></span>
                    <strong>#<?php echo $order->get_id(); ?></strong>
                </div>
            </div>
            
            <a href="<?php echo esc_url($payment_url); ?>" class="button alt sberbank-pay-button">
                <?php _e('Перейти к оплате', 'sberbank-payment-gateway'); ?>
                <span class="dashicons dashicons-arrow-right-alt"></span>
            </a>
            
            <?php if ($gateway->auto_redirect): ?>
            <div class="sberbank-auto-redirect">
                <p><?php _e('Автоматическое перенаправление через:', 'sberbank-payment-gateway'); ?></p>
                <div class="sberbank-countdown">5</div>
            </div>
            <script>
                jQuery(document).ready(function($) {
                    var countdown = 5;
                    var countdownElement = $('.sberbank-countdown');
                    
                    var interval = setInterval(function() {
                        countdown--;
                        countdownElement.text(countdown);
                        
                        if (countdown <= 0) {
                            clearInterval(interval);
                            window.location.href = '<?php echo esc_url($payment_url); ?>';
                        }
                    }, 1000);
                });
            </script>
            <?php endif; ?>
        </div>
        
        <div class="sberbank-payment-footer">
            <div class="sberbank-security-info">
                <span class="dashicons dashicons-lock"></span>
                <span><?php _e('Безопасная оплата через Сбербанк', 'sberbank-payment-gateway'); ?></span>
            </div>
        </div>
    </div>
</div>

<style>
.sberbank-payment-form {
    max-width: 500px;
    margin: 20px auto;
}

.sberbank-payment-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
    overflow: hidden;
}

.sberbank-payment-header {
    background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
    color: white;
    padding: 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.sberbank-payment-header h3 {
    margin: 0;
    font-size: 18px;
}

.sberbank-logo img {
    height: 30px;
}

.sberbank-payment-content {
    padding: 30px;
}

.sberbank-order-details {
    background: #f8f9fa;
    padding: 20px;
    border-radius: 8px;
    margin: 20px 0;
}

.sberbank-order-amount,
.sberbank-order-number {
    display: flex;
    justify-content: space-between;
    margin-bottom: 10px;
}

.sberbank-order-amount:last-child,
.sberbank-order-number:last-child {
    margin-bottom: 0;
}

.sberbank-pay-button {
    background: linear-gradient(135deg, #28a745 0%, #20c997 100%) !important;
    border: none !important;
    color: white !important;
    padding: 15px 30px !important;
    font-size: 16px !important;
    font-weight: 600 !important;
    border-radius: 8px !important;
    text-align: center;
    display: block;
    width: 100%;
    text-decoration: none;
    transition: transform 0.2s ease;
}

.sberbank-pay-button:hover {
    transform: translateY(-2px);
    color: white !important;
}

.sberbank-auto-redirect {
    text-align: center;
    margin-top: 20px;
    padding: 15px;
    background: #e3f2fd;
    border-radius: 8px;
}

.sberbank-countdown {
    font-size: 24px;
    font-weight: bold;
    color: #007bff;
    margin-top: 10px;
}

.sberbank-payment-footer {
    background: #f8f9fa;
    padding: 15px 20px;
    text-align: center;
}

.sberbank-security-info {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    color: #6c757d;
    font-size: 14px;
}

@media (max-width: 768px) {
    .sberbank-payment-content {
        padding: 20px;
    }
    
    .sberbank-payment-header {
        flex-direction: column;
        gap: 10px;
        text-align: center;
    }
}
</style>
