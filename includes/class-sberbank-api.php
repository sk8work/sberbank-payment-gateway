<?php
if (!defined('ABSPATH')) {
    exit;
}

class Sberbank_API {
    
    private $gateway;
    
    public function __construct($gateway) {
        $this->gateway = $gateway;
    }

    /**
     * Регистрация заказа в платежном шлюзе Сбербанка
     *
     * @param WC_Order $order Объект заказа WooCommerce
     * @return array Ответ от API Сбербанка
     * @throws Exception
     */
    public function register_order($order) {
        $order_id = $order->get_id();
        $amount = $order->get_total();
        $currency = $order->get_currency();
        
        $request_data = array(
            'userName'      => $this->gateway->merchant_login,
            'password'      => $this->gateway->merchant_password,
            'orderNumber'   => $this->generate_order_number($order_id),
            'amount'        => $this->format_amount($amount),
            'currency'      => $this->get_currency_code($currency),
            'returnUrl'     => $this->gateway->get_return_url($order),
            'failUrl'       => $order->get_cancel_order_url(),
            'description'   => $this->get_order_description($order_id),
            'language'      => 'ru',
            'email'         => sanitize_email($order->get_billing_email()),
            'phone'         => $this->format_phone($order->get_billing_phone()),
            'orderBundle'   => $this->get_order_bundle($order)
        );

        $response = $this->send_request('register.do', $request_data);

        if ($response['errorCode'] == 0) {
            $order->update_meta_data('_sberbank_order_id', $response['orderId']);
            $order->save();
        }

        return $response;
    }

    /**
     * Проверка статуса заказа в платежном шлюзе
     *
     * @param int $order_id ID заказа WooCommerce
     * @return array Ответ от API Сбербанка
     * @throws Exception
     */
    public function get_order_status($order_id) {
        $order = wc_get_order($order_id);
        $sberbank_order_id = $order->get_meta('_sberbank_order_id');
        
        if (!$sberbank_order_id) {
            throw new Exception(__('ID заказа Сбербанка не найден', 'sberbank-payment-gateway'));
        }
        
        $request_data = array(
            'userName'  => $this->gateway->merchant_login,
            'password'  => $this->gateway->merchant_password,
            'orderId'   => $sberbank_order_id
        );

        return $this->send_request('getOrderStatusExtended.do', $request_data);
    }

    /**
     * Возврат средств по заказу
     *
     * @param WC_Order $order Объект заказа WooCommerce
     * @param float $amount Сумма возврата
     * @return array Ответ от API Сбербанка
     * @throws Exception
     */
    public function refund_order($order, $amount) {
        $sberbank_order_id = $order->get_meta('_sberbank_order_id');
        
        if (!$sberbank_order_id) {
            throw new Exception(__('ID заказа Сбербанка не найден', 'sberbank-payment-gateway'));
        }
        
        $request_data = array(
            'userName'  => $this->gateway->merchant_login,
            'password'  => $this->gateway->merchant_password,
            'orderId'   => $sberbank_order_id,
            'amount'    => $this->format_amount($amount)
        );

        return $this->send_request('refund.do', $request_data);
    }

    /**
     * Отправка запроса к API Сбербанка
     *
     * @param string $endpoint Конечная точка API
     * @param array $data Данные запроса
     * @return array Ответ от API
     * @throws Exception
     */
    private function send_request($endpoint, $data) {
        $url = $this->gateway->api_url . $endpoint;

        // Путь к сертификату
        $cert_path = SBERBANK_PAYMENT_GATEWAY_PLUGIN_DIR . 'certs/russian_trusted_root_ca.cer';
        
        $args = array(
            'body'      => json_encode($data, JSON_UNESCAPED_UNICODE),
            'headers'   => array(
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json'
            ),
            'timeout'   => 30,
            'sslverify' => $this->gateway->get_option('ssl_verify') === 'yes'
        );

        // Добавляем путь к CA-сертификату если проверка SSL включена и файл существует
        if ($this->gateway->get_option('ssl_verify') === 'yes' && file_exists($cert_path)) {
            $args['sslcertificates'] = $cert_path;
        }

        $response = wp_remote_post($url, $args);

        if (is_wp_error($response)) {
            throw new Exception(__('Ошибка соединения с платежным шлюзом: ', 'sberbank-payment-gateway') . $response->get_error_message());
        }

        $response_code  = wp_remote_retrieve_response_code($response);
        $response_body  = wp_remote_retrieve_body($response);
        $result         = json_decode($response_body, true);

        if ($this->gateway->logging) {
            $this->log_request($endpoint, $data, $result, $response_code);
        }

        if ($response_code != 200) {
            throw new Exception(__('Сервер платежного шлюза вернул ошибку: HTTP ', 'sberbank-payment-gateway') . $response_code);
        }

        if (!$result || !isset($result['errorCode'])) {
            throw new Exception(__('Неверный формат ответа от платежного шлюза', 'sberbank-payment-gateway'));
        }

        if ($result['errorCode'] != 0) {
            $error_message = isset($result['errorMessage']) ? $result['errorMessage'] : __('Неизвестная ошибка платежного шлюза', 'sberbank-payment-gateway');
            throw new Exception($error_message);
        }

        return $result;
    }

    /**
     * Генерация номера заказа для платежного шлюза
     *
     * @param int $order_id ID заказа WooCommerce
     * @return string Уникальный номер заказа
     */
    private function generate_order_number($order_id) {
        return $order_id . '_' . time() . '_' . wp_rand(1000, 9999);
    }

    /**
     * Форматирование суммы для платежного шлюза (в копейках)
     *
     * @param float $amount Сумма
     * @return int Сумма в минимальных единицах валюты
     */
    private function format_amount($amount) {
        return (int)round($amount * 100);
    }

    /**
     * Получение кода валюты по ISO 4217
     *
     * @param string $currency Валюта (RUB, USD, EUR)
     * @return int Код валюты
     */
    private function get_currency_code($currency) {
        $currencies = array(
            'RUB' => 643,
            'USD' => 840,
            'EUR' => 978,
            'BYN' => 933,
            'KZT' => 398,
            'UAH' => 980,
            'CNY' => 156,
            'GBP' => 826,
            'JPY' => 392
        );
        return isset($currencies[$currency]) ? $currencies[$currency] : 643;
    }

    /**
     * Формирование описания заказа
     *
     * @param int $order_id ID заказа WooCommerce
     * @return string Описание заказа
     */
    private function get_order_description($order_id) {
        $description = sprintf(__('Оплата заказа №%s', 'sberbank-payment-gateway'), $order_id);
        
        // Ограничиваем длину описания (максимум 512 символов по API)
        if (mb_strlen($description) > 512) {
            $description = mb_substr($description, 0, 509) . '...';
        }
        
        return $description;
    }

    /**
     * Форматирование номера телефона
     *
     * @param string $phone Номер телефона
     * @return string Отформатированный номер
     */
    private function format_phone($phone) {
        if (empty($phone)) {
            return '';
        }
        
        $phone = preg_replace('/[^0-9+]/', '', $phone);
        
        // Если номер начинается с 8, заменяем на +7
        if (substr($phone, 0, 1) === '8' && strlen($phone) === 11) {
            $phone = '+7' . substr($phone, 1);
        }
        // Если номер без кода страны, добавляем +7
        elseif (substr($phone, 0, 1) !== '+' && strlen($phone) === 10) {
            $phone = '+7' . $phone;
        }
        
        return substr($phone, 0, 16);
    }

    /**
     * Формирование данных корзины для фискализации
     *
     * @param WC_Order $order Объект заказа WooCommerce
     * @return array Данные корзины
     */
    private function get_order_bundle($order) {
        // Если фискализация отключена, возвращаем пустой массив
        if ($this->gateway->get_option('ffd_enabled') !== 'yes') {
            return array();
        }

        $items = array();
        $total_amount = 0;

        // Товары в заказе
        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();
            
            if (!$product) {
                continue;
            }
            
            $tax_rate = $this->get_tax_rate_for_product($product);
            $quantity = $item->get_quantity();
            $item_total = $item->get_total();
            $item_price = $quantity > 0 ? $item_total / $quantity : 0;
            
            $item_data = array(
                'positionId'    => $item_id,
                'name'          => $this->sanitize_item_name($item->get_name()),
                'quantity'      => array(
                    'value'     => $quantity,
                ),
                'measurementUnit'   => $this->get_measure_code($product),
                'itemAmount'    => $this->format_amount($item_total),
                'itemPrice'     => $this->format_amount($item_price),
                'itemCode'      => $product->get_id(),
                'tax'           => array(
                    'taxType'   => $this->get_tax_code($tax_rate),
                ),
                'paymentMethod' => 'full_payment',
                'paymentObject' => $this->get_payment_object($product),
            );
            
            $items[] = $item_data;
            $total_amount += $item_total;
        }

        // Доставка
        if ($order->get_shipping_total() > 0) {
            $shipping_item = array(
                'positionId'    => 'delivery_' . sanitize_title($order->get_shipping_method()),
                'name'          => __('Доставка', 'sberbank-payment-gateway'),
                'quantity'      => array(
                    'value'     => 1,
                ),
                'measurementUnit'   => $this->get_measure_code(null),
                'itemAmount'    => $this->format_amount($order->get_shipping_total()),
                'itemPrice'     => $this->format_amount($order->get_shipping_total()),
                'itemCode'      => 'delivery',
                'tax'           => array(
                    'taxType'   => $this->get_tax_code($this->gateway->tax_system == 'osn' ? 20 : 0),
                ),
                'paymentMethod' => 'full_payment',
                'paymentObject' => '4', // Услуга
            );
            
            $items[] = $shipping_item;
            $total_amount += $order->get_shipping_total();
        }

        // Скидки
        if ($order->get_discount_total() > 0 && !empty($items)) {
            $discount_total = $order->get_discount_total();
            $discount_per_item = $discount_total / count($items);
            
            foreach ($items as &$item) {
                $item_discount = min($discount_per_item, $item['itemAmount'] / 100);
                $item['itemAmount'] = max(0, $item['itemAmount'] - $this->format_amount($item_discount));
                
                if ($item['quantity']['value'] > 0) {
                    $item['itemPrice'] = $item['itemAmount'] / $item['quantity']['value'];
                }
            }
        }

        // Формируем orderBundle согласно формату Сбербанка
        $order_bundle = array(
            'orderCreationDate' => $order->get_date_created()->date('Y-m-d\TH:i:s'),
            'customerDetails' => array(
                'email' => sanitize_email($order->get_billing_email()),
                'phone' => $this->format_phone($order->get_billing_phone()),
                'contact' => $order->get_meta('_billing__first_name'),
            ),
            'total' => $total_amount,
            'cartItems' => array(
                'items' => $items
            )
        );

        // Добавляем фискальные данные если включена фискализация
        if ($this->gateway->get_option('ffd_enabled') === 'yes') {
            $order_bundle = array_merge($order_bundle, array(
                'ffdVersion' => $this->gateway->ffd_version,
                'receiptType' => 'sell',
                'taxationSystem' => $this->get_taxation_system_code($this->gateway->tax_system),
                'clientInfo' => array(
                    'email' => $this->gateway->company_email ?: get_option('admin_email'),
                ),
                'companyInfo' => array(
                    'email' => $this->gateway->company_email ?: get_option('admin_email'),
                    'sno' => $this->gateway->tax_system,
                    'inn' => $this->gateway->company_inn,
                    'paymentAddress' => $this->gateway->payment_address ?: get_site_url()
                )
            ));
        }

        return $order_bundle;
    }

    /**
     * Санитизация названия товара
     *
     * @param string $name Название товара
     * @return string Очищенное название
     */
    private function sanitize_item_name($name) {
        $name = sanitize_text_field($name);
        $name = mb_substr($name, 0, 128); // Ограничиваем длину
        return $name;
    }

    /**
     * Получение ставки НДС для товара
     *
     * @param WC_Product $product Объект товара WooCommerce
     * @return float Ставка НДС
     */
    private function get_tax_rate_for_product($product) {
        $tax_class = $product->get_tax_class();
        $tax_rates = WC_Tax::get_rates($tax_class);
        
        if (!empty($tax_rates)) {
            $tax_rate = reset($tax_rates);
            return $tax_rate['rate'];
        }
        
        // Возвращаем ставку по умолчанию в зависимости от системы налогообложения
        switch ($this->gateway->tax_system) {
            case 'osn':
                return 20;
            case 'usn_income':
            case 'usn_income_outcome':
                return 0;
            default:
                return 0;
        }
    }

    /**
     * Получение кода налога для платежного шлюза
     *
     * @param float $tax_rate Ставка налога
     * @return int Код налога
     */
    private function get_tax_code($tax_rate) {
        $tax_codes = array(
            0   => 6,   // без НДС
            10  => 2,   // НДС 10%
            20  => 1,   // НДС 20%
            18  => 1,   // НДС 18% (старая ставка)
            5   => 5,   // НДС 5%
            7   => 7    // НДС 7%
        );
        
        // Округляем ставку налога
        $rounded_rate = round($tax_rate);
        
        return isset($tax_codes[$rounded_rate]) ? $tax_codes[$rounded_rate] : 6;
    }

    /**
     * Получение кода системы налогообложения
     *
     * @param string $tax_system Система налогообложения
     * @return int Код системы налогообложения
     */
    private function get_taxation_system_code($tax_system) {
        $codes = array(
            'osn'                   => 0,
            'usn_income'            => 1,
            'usn_income_outcome'    => 2,
            'envd'                  => 3,
            'esn'                   => 4,
            'patent'                => 5
        );
        
        return isset($codes[$tax_system]) ? $codes[$tax_system] : 0;
    }

    /**
     * Получение кода единицы измерения
     *
     * @param WC_Product|null $product Объект товара WooCommerce
     * @return string Код единицы измерения
     */
    private function get_measure_code($product) {
        if ($this->gateway->ffd_version == '1.2') {
            return 'шт.'; // штуки
        } else {
            return 'шт.';
        }
    }

    /**
     * Получение кода объекта платежа
     *
     * @param WC_Product $product Объект товара WooCommerce
     * @return string Код объекта платежа
     */
    private function get_payment_object($product) {
        if ($product->is_downloadable()) {
            return '4'; // электронный товар
        } elseif ($product->is_virtual()) {
            return '4'; // услуга
        } else {
            return '1'; // товар
        }
    }

    /**
     * Логирование запросов и ответов
     *
     * @param string $endpoint Конечная точка API
     * @param array $request Данные запроса
     * @param array $response Ответ от API
     * @param int $response_code HTTP код ответа
     */
    private function log_request($endpoint, $request, $response, $response_code) {
        $log_data = array(
            'date'          => current_time('mysql'),
            'endpoint'      => $endpoint,
            'request'       => $this->sanitize_log_data($request),
            'response'      => $this->sanitize_log_data($response),
            'response_code' => $response_code
        );

        $log_file = WP_CONTENT_DIR . '/plugins/sberbank-payment-gateway/sberbank-payment-gateway.log';
        $log_entry = "[" . $log_data['date'] . "] " . $endpoint . " (HTTP " . $response_code . ")\n";
        $log_entry .= "Request: " . json_encode($log_data['request'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        $log_entry .= "Response: " . json_encode($log_data['response'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        $log_entry .= "--------------------------------------------------\n\n";

        file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Санитизация данных для лога (скрываем чувствительную информацию)
     *
     * @param array $data Данные для лога
     * @return array Очищенные данные
     */
    private function sanitize_log_data($data) {
        if (!is_array($data)) {
            return $data;
        }
        
        $sensitive_keys = array('password', 'userName', 'merchant_login', 'merchant_password', 'PAN', 'CVC', 'cardNumber', 'cardCvc');
        
        foreach ($data as $key => $value) {
            if (in_array($key, $sensitive_keys)) {
                $data[$key] = '***HIDDEN***';
            } elseif (is_array($value)) {
                $data[$key] = $this->sanitize_log_data($value);
            }
        }
        
        return $data;
    }

    /**
     * Тестовое подключение к API
     *
     * @return bool Результат проверки подключения
     */
    public function test_connection() {
        try {
            $request_data = array(
                'userName'  => $this->gateway->merchant_login,
                'password'  => $this->gateway->merchant_password
            );
            
            $response = $this->send_request('getOrderStatus.do', $request_data);
            
            // Если запрос прошел без исключений, считаем подключение успешным
            return true;
            
        } catch (Exception $e) {
            // Ловим конкретные ошибки аутентификации
            if (strpos($e->getMessage(), 'Ошибка аутентификации') !== false ||
                strpos($e->getMessage(), 'Неверные учетные данные') !== false) {
                return false;
            }
            
            // Для других ошибок считаем подключение успешным (сервер ответил)
            return true;
        }
    }
}
