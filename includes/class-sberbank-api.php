<?php
if (!defined('ABSPATH')) {
    exit;
}

class Sberbank_API {
    
    private $gateway;
    
    public function __construct($gateway) {
        $this->gateway = $gateway;
    }
    
    public function register_order($order) {
        $order_id       = $order->get_id();
        $amount         = $order->get_total();
        $currency       = $order->get_currency();
        $description    = sprintf(__('Оплата заказа №%s', 'sberbank-payment-gateway'), $order_id);
        
        $request_data = array(
            'userName'      => $this->gateway->merchant_login,
            'password'      => $this->gateway->merchant_password,
            'orderNumber'   => $order_id . '_' . time(),
            'amount'        => $amount * 100, // Сумма в копейках
            'currency'      => $this->get_currency_code($currency),
            'returnUrl'     => $this->gateway->get_return_url($order),
            'failUrl'       => $order->get_cancel_order_url(),
            'description'   => $description,
            'language'      => 'ru',
        );
        
        // Добавляем данные для ФФД, если включено
        if ($this->gateway->ffd_version) {
            $request_data = $this->add_ffd_data($request_data, $order);
        }
        
        // Добавляем данные покупателя
        $request_data['email'] = $order->get_billing_email();
        $request_data['phone'] = preg_replace('/[^0-9]/', '', $order->get_billing_phone());
        
        $method     = $this->gateway->two_stage ? 'registerPreAuth.do' : 'register.do';
        $response   = $this->send_request($method, $request_data);
        
        if ($response['errorCode'] == 0) {
            // Сохраняем ID платежа в метаданные заказа
            $order->update_meta_data('_sberbank_order_id', $response['orderId']);
            $order->save();
        }
        
        return $response;
    }
    
    public function refund_order($order, $amount) {
        $sberbank_order_id = $order->get_meta('_sberbank_order_id');
        
        if (!$sberbank_order_id) {
            throw new Exception(__('ID заказа в Сбербанке не найден', 'sberbank-payment-gateway'));
        }
        
        $request_data = array(
            'userName'  => $this->gateway->merchant_login,
            'password'  => $this->gateway->merchant_password,
            'orderId'   => $sberbank_order_id,
            'amount'    => $amount * 100, // Сумма в копейках
        );
        
        // Добавляем данные для ФФД, если включено
        if ($this->gateway->ffd_version) {
            $request_data = $this->add_ffd_refund_data($request_data, $order, $amount);
        }
        
        return $this->send_request('refund.do', $request_data);
    }
    
    public function get_order_status($order) {
        $sberbank_order_id = $order->get_meta('_sberbank_order_id');
        
        if (!$sberbank_order_id) {
            throw new Exception(__('ID заказа в Сбербанке не найден', 'sberbank-payment-gateway'));
        }
        
        $request_data = array(
            'userName'  => $this->gateway->merchant_login,
            'password'  => $this->gateway->merchant_password,
            'orderId'   => $sberbank_order_id,
        );
        
        return $this->send_request('getOrderStatusExtended.do', $request_data);
    }
    
    private function add_ffd_data($request_data, $order) {
        $items          = array();
        $total_amount   = 0;
        
        // Товары в заказе
        foreach ($order->get_items() as $item_id => $item) {
            $product    = $item->get_product();
            $tax_rate   = $this->get_tax_rate_for_product($product);
            
            $item_data = array(
                'positionId'    => $item_id,
                'name'          => $item->get_name(),
                'quantity'      => array(
                    'value'     => $item->get_quantity(),
                    'measure'   => $this->get_measure_code($product),
                ),
                'itemAmount'    => $item->get_total() * 100,
                'itemPrice'     => $item->get_subtotal() / $item->get_quantity() * 100,
                'itemCode'      => $product->get_id(),
                'tax'           => array(
                    'taxType' => $this->get_tax_code($tax_rate),
                ),
                'paymentMethod' => 'full_payment',
                'paymentObject' => $this->get_payment_object($product),
            );
            
            $items[] = $item_data;
            $total_amount += $item->get_total();
        }
        
        // Доставка
        if ($order->get_shipping_total() > 0) {
            $shipping_item = array(
                'positionId'    => 'delivery_' . $order->get_shipping_method(),
                'name'          => __('Доставка', 'sberbank-payment-gateway'),
                'quantity'      => array(
                    'value'     => 1,
                    'measure'   => '0',
                ),
                'itemAmount'    => $order->get_shipping_total() * 100,
                'itemPrice'     => $order->get_shipping_total() * 100,
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
        if ($order->get_discount_total() > 0) {
            // Распределяем скидку пропорционально между товарами
            $discount_per_item = $order->get_discount_total() / count($items);
            
            foreach ($items as &$item) {
                $item['itemAmount'] = max(0, $item['itemAmount'] - ($discount_per_item * 100));
                $item['itemPrice']  = $item['itemAmount'] / ($item['quantity']['value'] * 100);
            }
        }
        
        $request_data['orderBundle'] = array(
            'orderCreationDate' => date('c'),
            'customerDetails'   => array(
                'email' => $order->get_billing_email(),
                'phone' => preg_replace('/[^0-9]/', '', $order->get_billing_phone()),
            ),
            'cartItems' => array(
                'items' => $items,
            ),
            'ffdVersion'    => $this->gateway->ffd_version,
            'taxSystem'     => $this->gateway->tax_system,
        );
        
        return $request_data;
    }
    
    private function add_ffd_refund_data($request_data, $order, $amount) {
        // Для возвратов также нужно передавать данные о товарах
        $items = array();
        $total_amount = 0;
        
        // Товары в заказе
        foreach ($order->get_items() as $item_id => $item) {
            $product    = $item->get_product();
            $tax_rate   = $this->get_tax_rate_for_product($product);
            
            // Распределяем сумму возврата пропорционально стоимости товаров
            $item_ratio     = $item->get_total() / $order->get_total();
            $refund_amount  = $amount * $item_ratio;
            
            $item_data = array(
                'positionId'    => $item_id,
                'name'          => $item->get_name(),
                'quantity'      => array(
                    'value'     => $item->get_quantity(),
                    'measure'   => $this->get_measure_code($product),
                ),
                'itemAmount'    => $refund_amount * 100,
                'itemPrice'     => $item->get_subtotal() / $item->get_quantity() * 100,
                'itemCode'      => $product->get_id(),
                'tax'           => array(
                    'taxType' => $this->get_tax_code($tax_rate),
                ),
                'paymentMethod' => 'full_payment',
                'paymentObject' => $this->get_payment_object($product),
            );
            
            $items[] = $item_data;
        }
        
        $request_data['orderBundle'] = array(
            'orderCreationDate' => date('c'),
            'cartItems' => array(
                'items' => $items,
            ),
            'ffdVersion'    => $this->gateway->ffd_version,
            'taxSystem'     => $this->gateway->tax_system,
            'receiptType'   => 'refund',
        );
        
        return $request_data;
    }
    
    private function send_request($method, $data) {
        $url = $this->gateway->api_url . $method;
        
        
        $args = array(
            'body'      => json_encode($data, JSON_UNESCAPED_UNICODE),
            'headers'   => array(
                'Content-Type' => 'application/json',
            ),
            'timeout' => 30,
        );
        
        $response = wp_remote_post($url, $args);
        
        if (is_wp_error($response)) {
            throw new Exception($response->get_error_message());
        }
        
        $response_body  = wp_remote_retrieve_body($response);
        $result         = json_decode($response_body, true);
        
        if ($this->gateway->logging) {
            $this->log($method, $data, $result);
        }
        
        if (!$result || !isset($result['errorCode'])) {
            throw new Exception(__('Неверный ответ от сервера Сбербанка', 'sberbank-payment-gateway'));
        }
        
        return $result;
    }
    
    private function get_currency_code($currency) {
        $currencies = array(
            'RUB' => 643,
            'USD' => 840,
            'EUR' => 978,
            'BYN' => 933,
        );
        
        return isset($currencies[$currency]) ? $currencies[$currency] : 643;
    }
    
    private function get_tax_code($tax_rate) {
        $tax_codes = array(
            0   => 0,   // без НДС
            10  => 2,   // НДС 10%
            20  => 6,   // НДС 20%
            5   => 8,    // НДС 5%
            7   => 10,   // НДС 7%
        );
        
        return isset($tax_codes[$tax_rate]) ? $tax_codes[$tax_rate] : 0;
    }
    
    private function get_tax_rate_for_product($product) {
        $tax_class = $product->get_tax_class();
        $tax_rates = WC_Tax::get_rates($tax_class);
        
        if (!empty($tax_rates)) {
            $tax_rate = reset($tax_rates);
            return $tax_rate['rate'];
        }
        
        return 0;
    }
    
    private function get_measure_code($product) {
        if ($this->gateway->ffd_version == '1.2') {
            // Для ФФД 1.2 используем коды
            return '0'; // штуки
        } else {
            // Для ФФД 1.05 используем названия
            return 'шт';
        }
    }
    
    private function get_payment_object($product) {
        if ($this->gateway->ffd_version == '1.2') {
            // Для ФФД 1.2 используем коды
            return '1'; // товар
        } else {
            // Для ФФД 1.05 используем названия
            return 'commodity';
        }
    }
    
    private function log($method, $request, $response) {
        $log = sprintf(
            "[%s] Method: %s\nRequest: %s\nResponse: %s\n\n",
            date('Y-m-d H:i:s'),
            $method,
            print_r($request, true),
            print_r($response, true)
        );
        
        $log_file = WP_CONTENT_DIR . '/sberbank-payment-gateway.log';
        file_put_contents($log_file, $log, FILE_APPEND);
    }
}