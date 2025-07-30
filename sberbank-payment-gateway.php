<?php
/**
 * Plugin Name: Sberbank Payment Gateway
 * Description: Платежный шлюз Сбербанка для WooCommerce
 * Version: 1.0.0
 * Author: sk8work
 * Author URI: https://sk8work.ru
 * Text Domain: sberbank-payment-gateway
 * Domain Path: /languages
 * Requires at least: 5.6
 * Requires PHP: 7.2
 */

defined('ABSPATH') || exit;

// Определяем константы плагина
define('SBERBANK_PAYMENT_GATEWAY_VERSION', '1.0.0');
define('SBERBANK_PAYMENT_GATEWAY_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SBERBANK_PAYMENT_GATEWAY_PLUGIN_URL', plugin_dir_url(__FILE__));

// Инициализация плагина
add_action('plugins_loaded', 'init_sberbank_payment_gateway', 0);

function init_sberbank_payment_gateway() {
    // Проверяем, активирован ли WooCommerce
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function() {
            echo '<div class="error"><p>';
            _e('Для работы плагина Sberbank Payment Gateway необходимо установить и активировать плагин WooCommerce.', 'sberbank-payment-gateway');
            echo '</p></div>';
        });
        return;
    }

    // Подключаем файлы классов только после проверки WooCommerce
    require_once SBERBANK_PAYMENT_GATEWAY_PLUGIN_DIR . 'includes/class-sberbank-api.php';
    require_once SBERBANK_PAYMENT_GATEWAY_PLUGIN_DIR . 'includes/class-sberbank-gateway.php';
    require_once SBERBANK_PAYMENT_GATEWAY_PLUGIN_DIR . 'includes/class-sberbank-order-handler.php';
    require_once SBERBANK_PAYMENT_GATEWAY_PLUGIN_DIR . 'includes/class-sberbank-settings.php';
    
    // Загрузка текстового домена
    load_plugin_textdomain('sberbank-payment-gateway', false, dirname(plugin_basename(__FILE__)) . '/languages');
    
    // Регистрация платежного шлюза
    add_filter('woocommerce_payment_gateways', 'add_sberbank_payment_gateway');
    
    function add_sberbank_payment_gateway($gateways) {
        $gateways[] = 'WC_Sberbank_Payment_Gateway';
        return $gateways;
    }
}

// Регистрируем обработчик callback от Сбербанка
add_action('init', 'register_sberbank_callback_handler');

function register_sberbank_callback_handler() {
    if (isset($_GET['sberbank_callback'])) {
        // Убедимся, что WooCommerce загружен
        if (!class_exists('WooCommerce')) {
            status_header(500);
            exit;
        }
        
        $handler = new Sberbank_Order_Handler();
        $handler->process_callback();
        exit;
    }
}