<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WC_Payment_Gateway')) {
    return;
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
        $this->company_inn          = $this->get_option('company_inn', '');
        $this->company_email        = $this->get_option('company_email', '');
        $this->payment_address      = $this->get_option('payment_address', '');
        
        // API URL
        $this->api_url = $this->test_mode ? 
            'https://ecomtest.sberbank.ru/ecomm/gw/partner/api/v1/' : 
            'https://ecommerce.sberbank.ru/ecomm/gw/partner/api/v1/';
        
        // Callback URL
        $this->callback_url = home_url('/?sberbank_callback=1');
        
        // Действия
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));
        add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));
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
            'merchant_settings' => array(
                'title'       => __('Настройки мерчанта', 'sberbank-payment-gateway'),
                'type'        => 'title',
                'description' => __('Данные для подключения к API Сбербанка', 'sberbank-payment-gateway'),
            ),
            'merchant_login' => array(
                'title'       => __('Логин мерчанта', 'sberbank-payment-gateway'),
                'type'        => 'text',
                'description' => __('Логин для доступа к API Сбербанка', 'sberbank-payment-gateway'),
                'default'     => '',
                'class'       => 'sberbank-field'
            ),
            'merchant_password' => array(
                'title'       => __('Пароль мерчанта', 'sberbank-payment-gateway'),
                'type'        => 'password',
                'description' => __('Пароль для доступа к API Сбербанка', 'sberbank-payment-gateway'),
                'default'     => '',
                'class'       => 'sberbank-field'
            ),
            'test_mode' => array(
                'title'       => __('Тестовый режим', 'sberbank-payment-gateway'),
                'type'        => 'checkbox',
                'label'       => __('Включить тестовый режим', 'sberbank-payment-gateway'),
                'default'     => 'no',
                'description' => __('Использовать тестовый сервер Сбербанка', 'sberbank-payment-gateway'),
            ),
            'payment_settings' => array(
                'title'       => __('Настройки оплаты', 'sberbank-payment-gateway'),
                'type'        => 'title',
                'description' => __('Настройки процесса оплаты', 'sberbank-payment-gateway'),
            ),
            'two_stage' => array(
                'title'       => __('Двухстадийная оплата', 'sberbank-payment-gateway'),
                'type'        => 'checkbox',
                'label'       => __('Включить двухстадийную оплату', 'sberbank-payment-gateway'),
                'default'     => 'no',
                'description' => __('При двухстадийной оплате средства сначала блокируются, затем списываются', 'sberbank-payment-gateway'),
            ),
            'auto_redirect' => array(
                'title'       => __('Автоматический редирект', 'sberbank-payment-gateway'),
                'type'        => 'checkbox',
                'label'       => __('Автоматически перенаправлять на страницу оплаты', 'sberbank-payment-gateway'),
                'default'     => 'no',
                'description' => __('Покупатель будет автоматически перенаправлен на страницу оплаты Сбербанка', 'sberbank-payment-gateway'),
            ),
            'fiscal_settings' => array(
                'title'       => __('Фискальные настройки', 'sberbank-payment-gateway'),
                'type'        => 'title',
                'description' => __('Настройки для фискализации чеков', 'sberbank-payment-gateway'),
            ),
            'ffd_enabled' => array(
                'title'   => __('Фискализация (ФФД)', 'sberbank-payment-gateway'),
                'type'    => 'checkbox',
                'label'   => __('Включить передачу фискальных данных', 'sberbank-payment-gateway'),
                'default' => 'no',
                'description' => __('Отметьте для отправки данных по ФФД', 'sberbank-payment-gateway')
            ),
            'ffd_version'   => array(
                'title'       => __('Версия ФФД', 'sberbank-payment-gateway'),
                'type'        => 'select',
                'options'     => array(
                    ''      => __('Отключено', 'sberbank-payment-gateway'),
                    '1.05'  => __('ФФД 1.05', 'sberbank-payment-gateway'),
                    '1.2'   => __('ФФД 1.2', 'sberbank-payment-gateway')
                ),
                'default'     => '',
                'description' => __('Выберите версию формата фискальных данных', 'sberbank-payment-gateway'),
                'class'       => 'sberbank-ffd-field'
            ),
            'tax_system' => array(
                'title'       => __('Система налогообложения', 'sberbank-payment-gateway'),
                'type'        => 'select',
                'options'     => array(
                    'osn'                   => __('Общая', 'sberbank-payment-gateway'),
                    'usn_income'            => __('Упрощенная (доходы)', 'sberbank-payment-gateway'),
                    'usn_income_outcome'    => __('Упрощенная (доходы минус расходы)', 'sberbank-payment-gateway'),
                    'patent'                => __('Патентная', 'sberbank-payment-gateway'),
                    'envd'                  => __('Единый налог на вмененный доход', 'sberbank-payment-gateway'),
                    'esn'                   => __('Единый сельскохозяйственный налог', 'sberbank-payment-gateway'),
                ),
                'default'     => 'osn',
                'description' => __('Выберите систему налогообложения для чеков', 'sberbank-payment-gateway'),
                'class'       => 'sberbank-ffd-field'
            ),
            'company_inn' => array(
                'title'       => __('ИНН организации', 'sberbank-payment-gateway'),
                'type'        => 'text',
                'description' => __('ИНН вашей организации для фискальных данных', 'sberbank-payment-gateway'),
                'default'     => '',
                'class'       => 'sberbank-ffd-field'
            ),
            'company_email' => array(
                'title'       => __('Email организации', 'sberbank-payment-gateway'),
                'type'        => 'email',
                'description' => __('Email для отправки электронных чеков', 'sberbank-payment-gateway'),
                'default'     => get_option('admin_email'),
                'class'       => 'sberbank-ffd-field'
            ),
            'payment_address' => array(
                'title'       => __('Адрес места расчетов', 'sberbank-payment-gateway'),
                'type'        => 'text',
                'description' => __('URL сайта или адрес места осуществления расчетов', 'sberbank-payment-gateway'),
                'default'     => get_site_url(),
                'class'       => 'sberbank-ffd-field'
            ),
            'advanced_settings' => array(
                'title'       => __('Дополнительные настройки', 'sberbank-payment-gateway'),
                'type'        => 'title',
                'description' => __('Расширенные настройки плагина', 'sberbank-payment-gateway'),
            ),
            'logging' => array(
                'title'       => __('Логирование', 'sberbank-payment-gateway'),
                'type'        => 'checkbox',
                'label'       => __('Включить логирование', 'sberbank-payment-gateway'),
                'default'     => 'no',
                'description' => __('Записывать события в лог для отладки', 'sberbank-payment-gateway'),
            ),
            'ssl_verify' => array(
                'title'       => __('Проверка SSL', 'sberbank-payment-gateway'),
                'type'        => 'checkbox',
                'label'       => __('Включить проверку SSL-сертификата', 'sberbank-payment-gateway'),
                'default'     => 'no',
                'description' => __('Рекомендуется отключать только для тестирования', 'sberbank-payment-gateway'),
            ),
        );
    }
    
    public function admin_options() {
        echo '<div class="sberbank-admin-header">';
        echo '<h2 style="color:white;">' . __('Настройки платежного шлюза Сбербанка', 'sberbank-payment-gateway') . '</h2>';
        echo '<div class="sberbank-admin-badge">v' . SBERBANK_PAYMENT_GATEWAY_VERSION . '</div>';
        echo '</div>';
        
        echo '<div class="sberbank-admin-container">';
        echo '<div class="sberbank-admin-sidebar">';
        echo '<div class="sberbank-admin-card">';
        echo '<h3>' . __('Статус подключения', 'sberbank-payment-gateway') . '</h3>';
        echo '<div class="sberbank-status">';
        
        if ($this->merchant_login && $this->merchant_password) {
            echo '<span class="sberbank-status-indicator connected"></span>';
            echo '<span>' . __('Подключено к API Сбербанка', 'sberbank-payment-gateway') . '</span>';
        } else {
            echo '<span class="sberbank-status-indicator disconnected"></span>';
            echo '<span>' . __('Требуется настройка подключения', 'sberbank-payment-gateway') . '</span>';
        }
        
        echo '</div>';
        echo '</div>';
        
        echo '<div class="sberbank-admin-card">';
        echo '<h3>' . __('Справка', 'sberbank-payment-gateway') . '</h3>';
        echo '<p>' . __('Для получения логина и пароля мерчанта обратитесь в техническую поддержку Сбербанка.', 'sberbank-payment-gateway') . '</p>';
        echo '<p><a href="https://developer.sberbank.ru/doc/api/platform" target="_blank">' . __('Документация API', 'sberbank-payment-gateway') . '</a></p>';
        echo '</div>';
        echo '</div>';
        
        echo '<div class="sberbank-admin-content">';
        parent::admin_options();
        echo '</div>';
        echo '</div>';
        
        $cert_path = SBERBANK_PAYMENT_GATEWAY_PLUGIN_DIR . 'certs/russian_trusted_root_ca.cer';
        if ($this->get_option('ssl_verify') === 'yes' && !file_exists($cert_path)) {
            echo '<div class="notice notice-warning"><p>';
            _e('Внимание: Файл сертификата CA не найден. SSL проверка может не работать корректно.', 'sberbank-payment-gateway');
            echo '</p></div>';
        }
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
                    'payment_url'   => $payment_url,
                    'order'         => $order,
                    'gateway'       => $this,
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
    
    public function admin_scripts() {
        wp_enqueue_style('sberbank-admin', SBERBANK_PAYMENT_GATEWAY_PLUGIN_URL . 'assets/css/admin.css', array(), SBERBANK_PAYMENT_GATEWAY_VERSION);
        wp_enqueue_script('sberbank-admin', SBERBANK_PAYMENT_GATEWAY_PLUGIN_URL . 'assets/js/admin.js', array('jquery'), SBERBANK_PAYMENT_GATEWAY_VERSION, true);
    }
    
    public function validate_company_inn_field($key, $value) {
        $value = sanitize_text_field($value);
        
        if (empty($value)) {
            WC_Admin_Settings::add_error(__('Ошибка: Поле ИНН организации обязательно для заполнения', 'sberbank-payment-gateway'));
            return '';
        }
        
        if (!preg_match('/^\d{10,12}$/', $value)) {
            WC_Admin_Settings::add_error(__('Ошибка: ИНН организации должен содержать 10 или 12 цифр', 'sberbank-payment-gateway'));
            return '';
        }
        
        return $value;
    }
}
