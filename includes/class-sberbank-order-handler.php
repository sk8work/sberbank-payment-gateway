<?php
if (!defined('ABSPATH')) {
    exit;
}

class Sberbank_Order_Handler {
    
    public function process_callback() {
        $raw_data   = file_get_contents('php://input');
        $data       = json_decode($raw_data, true);
        
        if (!$data || !isset($data['mdOrder'])) {
            status_header(400);
            exit;
        }
        
        $order_id = $this->get_order_id_from_callback($data);
        
        if (!$order_id) {
            status_header(404);
            exit;
        }
        
        $order = wc_get_order($order_id);
        
        if (!$order) {
            status_header(404);
            exit;
        }
        
        $gateway    = $this->get_gateway();
        $api        = new Sberbank_API($gateway);
        
        try {
            $status_response = $api->get_order_status($order);
            
            if ($status_response['errorCode'] == 0) {
                $this->update_order_status($order, $status_response);
            }
            
        } catch (Exception $e) {
            if ($gateway->logging) {
                $gateway->log('callback_error', $data, $e->getMessage());
            }
        }
        
        status_header(200);
        exit;
    }
    
    private function get_order_id_from_callback($data) {
        if (isset($data['orderNumber'])) {
            $parts = explode('_', $data['orderNumber']);
            return $parts[0];
        }
        return false;
    }
    
    private function update_order_status($order, $status_data) {
        $current_status = $order->get_status();
        
        if ($current_status == 'processing' || $current_status == 'completed') {
            return;
        }
        
        if ($status_data['orderStatus'] == 2) {
            $order->payment_complete($status_data['orderId']);
            $order->add_order_note(__('Платеж успешно завершен через Сбербанк', 'sberbank-payment-gateway'));
            
            if (isset($status_data['cardAuthInfo'])) {
                $card_info = $status_data['cardAuthInfo'];
                $order->update_meta_data('_sberbank_pan', $card_info['pan']);
                $order->update_meta_data('_sberbank_cardholder', $card_info['cardholderName']);
                $order->save();
            }
            
        } elseif ($status_data['orderStatus'] == 3 || $status_data['orderStatus'] == 6) {
            $order->update_status('failed', __('Платеж не прошел через Сбербанк', 'sberbank-payment-gateway'));
        } elseif ($status_data['orderStatus'] == 1 && $this->get_gateway()->two_stage) {
            $order->update_status('on-hold', __('Средства заблокированы (двухстадийный платеж)', 'sberbank-payment-gateway'));
        }
    }
    
    private function get_gateway() {
        $gateways = WC()->payment_gateways->payment_gateways();
        return $gateways['sberbank_payment_gateway'];
    }

    public function process_3ds_callback() {
        if (empty($_POST['MD']) || empty($_POST['PaRes'])) {
            status_header(400);
            exit;
        }
        
        $order_id = WC()->session->get('sberbank_3ds_order_id');
        
        if (!$order_id) {
            status_header(404);
            exit;
        }
        
        $order      = wc_get_order($order_id);
        $gateway    = $this->get_gateway();
        $api        = new Sberbank_API($gateway);
        
        try {
            $request_data = array(
                'userName'  => $gateway->merchant_login,
                'password'  => $gateway->merchant_password,
                'md'        => sanitize_text_field($_POST['MD']),
                'paRes'     => sanitize_text_field($_POST['PaRes']),
            );
            
            $response = $api->send_request('paymentOrderBinding.do', $request_data);
            
            if ($response['errorCode'] == 0) {
                $order->payment_complete($response['orderId']);
                wp_redirect($gateway->get_return_url($order));
                exit;
            } else {
                wc_add_notice(__('Ошибка 3DS аутентификации: ', 'sberbank-payment-gateway') . $response['errorMessage'], 'error');
                wp_redirect(wc_get_checkout_url());
                exit;
            }
        } catch (Exception $e) {
            wc_add_notice(__('Ошибка обработки платежа: ', 'sberbank-payment-gateway') . $e->getMessage(), 'error');
            wp_redirect(wc_get_checkout_url());
            exit;
        }
    }
}
