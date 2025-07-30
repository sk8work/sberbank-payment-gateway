<?php
if (!defined('ABSPATH')) {
    exit;
}

class WC_Sberbank_Payment_Gateway extends WC_Payment_Gateway {
    
    public function __construct() {
        $this->id                   = 'sberbank_payment_gateway';
        $this->method_title         = __('Сбербанк', 'sberbank-payment-gateway');
        $this->method_description   = __('Прием платежей через платежный шлюз Сбербанка', 'sberbank-payment-gateway');
        $this->has_fields           = false;
        $this->supports             = array('products', 'refunds');
        
        // Инициализация настроек
        $this->init_form_fields();
        $this->init_settings();
        
        // Настройки из админки
        $this->title                = $this->get_option('title');
        $this->description          = $this->get_option('description');
        $this->enabled              = $this->get_option('enabled');
        $this->test_mode            = $this->get_option('test_mode') === 'yes';
        $this->merchant_login       = $this->get_option('merchant_login');
        $this->merchant_password    = $this->get_option('merchant_password');
        $this->two_stage            = $this->get_option('two_stage') === 'yes';
        $this->auto_redirect        = $this->get_option('auto_redirect') === 'yes';
        $this->logging              = $this->get_option('logging') === 'yes';
        $this->tax_system           = $this->get_option('tax_system', 'osn');
        $this->ffd_version          = $this->get_option('ffd_version', '1.05');
        
        // API URL
        $this->api_url = $this->test_mode ? 
            'https://ecomtest.sberbank.ru/ecomm/gw/partner/api/v1/' : 
            'https://ecommerce.sberbank.ru/ecomm/gw/partner/api/v1/';
        
        // Callback URL
        $this->callback_url = home_url('/?sberbank_callback=1');
        
        // Действия
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));
    }
    
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'   => __('Включить/Выключить', 'sberbank-payment-gateway'),
                'type'    => 'checkbox',
                'label'   => __('Включить платежный шлюз Сбербанка', 'sberbank-payment-gateway'),
                'default' => 'yes',
            ),
            'title' => array(
                'title'       => __('Название', 'sberbank-payment-gateway'),
                'type'        => 'text',
                'description' => __('Название, которое пользователь видит при оформлении заказа', 'sberbank-payment-gateway'),
                'default'     => __('Сбербанк', 'sberbank-payment-gateway'),
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => __('Описание', 'sberbank-payment-gateway'),
                'type'        => 'textarea',
                'description' => __('Описание, которое пользователь видит при оформлении заказа', 'sberbank-payment-gateway'),
                'default'     => __('Оплата через платежный шлюз Сбербанка', 'sberbank-payment-gateway'),
            ),
            'merchant_login' => array(
                'title'       => __('Логин мерчанта', 'sberbank-payment-gateway'),
                'type'        => 'text',
                'description' => __('Логин для доступа к API Сбербанка', 'sberbank-payment-gateway'),
                'default'     => '',
            ),
            'merchant_password' => array(
                'title'       => __('Пароль мерчанта', 'sberbank-payment-gateway'),
                'type'        => 'password',
                'description' => __('Пароль для доступа к API Сбербанка', 'sberbank-payment-gateway'),
                'default'     => '',
            ),
            'test_mode' => array(
                'title'       => __('Тестовый режим', 'sberbank-payment-gateway'),
                'type'        => 'checkbox',
                'label'      => __('Включить тестовый режим', 'sberbank-payment-gateway'),
                'default'     => 'no',
                'description' => __('Использовать тестовый сервер Сбербанка', 'sberbank-payment-gateway'),
            ),
            'two_stage' => array(
                'title'       => __('Двухстадийная оплата', 'sberbank-payment-gateway'),
                'type'        => 'checkbox',
                'label'      => __('Включить двухстадийную оплату', 'sberbank-payment-gateway'),
                'default'     => 'no',
                'description' => __('При двухстадийной оплате средства сначала блокируются, затем списываются', 'sberbank-payment-gateway'),
            ),
            'auto_redirect' => array(
                'title'       => __('Автоматический редирект', 'sberbank-payment-gateway'),
                'type'        => 'checkbox',
                'label'      => __('Автоматически перенаправлять на страницу оплаты', 'sberbank-payment-gateway'),
                'default'     => 'no',
                'description' => __('Покупатель будет автоматически перенаправлен на страницу оплаты Сбербанка', 'sberbank-payment-gateway'),
            ),
            'logging' => array(
                'title'       => __('Логирование', 'sberbank-payment-gateway'),
                'type'        => 'checkbox',
                'label'      => __('Включить логирование', 'sberbank-payment-gateway'),
                'default'     => 'no',
                'description' => __('Записывать события в лог для отладки', 'sberbank-payment-gateway'),
            ),
            'tax_system' => array(
                'title'       => __('Система налогообложения', 'sberbank-payment-gateway'),
                'type'        => 'select',
                'options'     => array(
                    'osn' => __('Общая', 'sberbank-payment-gateway'),
                    'usn_income' => __('Упрощенная (доходы)', 'sberbank-payment-gateway'),
                    'usn_income_outcome' => __('Упрощенная (доходы минус расходы)', 'sberbank-payment-gateway'),
                    'patent' => __('Патентная', 'sberbank-payment-gateway'),
                    'envd' => __('Единый налог на вмененный доход', 'sberbank-payment-gateway'),
                    'esn' => __('Единый сельскохозяйственный налог', 'sberbank-payment-gateway'),
                ),
                'default'     => 'osn',
                'description' => __('Выберите систему налогообложения для чеков (уточните в бухгалтерии)', 'sberbank-payment-gateway'),
            ),
            'ffd_version' => array(
                'title'       => __('Версия ФФД', 'sberbank-payment-gateway'),
                'type'        => 'select',
                'options'     => array(
                    '1.05' => __('ФФД 1.05', 'sberbank-payment-gateway'),
                    '1.2' => __('ФФД 1.2', 'sberbank-payment-gateway'),
                ),
                'default'     => '1.05',
                'description' => __('Версия формата фискальных данных', 'sberbank-payment-gateway'),
            ),
        );
    }
    
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        
        return array(
            'result'   => 'success',
            'redirect' => $order->get_checkout_payment_url(true),
        );
    }
    
    public function receipt_page($order_id) {
        $order  = wc_get_order($order_id);
        $api    = new Sberbank_API($this);
        
        try {
            $response = $api->register_order($order);
            
            if ($response['errorCode'] == 0) {
                $payment_url = $response['formUrl'];
                
                if ($this->auto_redirect) {
                    wp_redirect($payment_url);
                    exit;
                }
                
                // Показываем кнопку для перехода на страницу оплаты
                wc_get_template('payment-form.php', array(
                    'payment_url' => $payment_url,
                    'order' => $order,
                    'gateway' => $this,
                ), '', SBERBANK_PAYMENT_GATEWAY_PLUGIN_DIR . 'templates/');
                
            } else {
                wc_add_notice(__('Ошибка при создании заказа в Сбербанке: ', 'sberbank-payment-gateway') . $response['errorMessage'], 'error');
                wp_redirect(wc_get_checkout_url());
                exit;
            }
            
        } catch (Exception $e) {
            wc_add_notice(__('Ошибка при обработке платежа: ', 'sberbank-payment-gateway') . $e->getMessage(), 'error');
            wp_redirect(wc_get_checkout_url());
            exit;
        }
    }
    
    public function process_refund($order_id, $amount = null, $reason = '') {
        $order  = wc_get_order($order_id);
        $api    = new Sberbank_API($this);
        
        try {
            $response = $api->refund_order($order, $amount);
            
            if ($response['errorCode'] == 0) {
                $order->add_order_note(sprintf(__('Возврат средств на сумму %s через Сбербанк выполнен успешно', 'sberbank-payment-gateway'), wc_price($amount)));
                return true;
            } else {
                $order->add_order_note(sprintf(__('Ошибка при возврате средств: %s (код %s)', 'sberbank-payment-gateway'), $response['errorMessage'], $response['errorCode']));
                return false;
            }
            
        } catch (Exception $e) {
            $order->add_order_note(__('Ошибка при обработке возврата: ', 'sberbank-payment-gateway') . $e->getMessage());
            return false;
        }
    }
}