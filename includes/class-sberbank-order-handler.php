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
        
        // Проверяем статус заказа в Сбербанке
        $gateway    = $this->get_gateway();
        $api        = new Sberbank_API($gateway);
        
        try {
            $status_response = $api->get_order_status($order);
            
            if ($status_response['errorCode'] == 0) {
                $this->update_order_status($order, $status_response);
            }
            
        } catch (Exception $e) {
            // Логируем ошибку, но не прерываем выполнение
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
        
        // Заказ уже обработан
        if ($current_status == 'processing' || $current_status == 'completed') {
            return;
        }
        
        // Статусы Сбербанка:
        // 0 - заказ зарегистрирован, но не оплачен
        // 1 - предавторизованная сумма захолдирована
        // 2 - проведена полная авторизация суммы заказа
        // 3 - авторизация отменена
        // 4 - по транзакции была проведена операция возврата
        // 5 - инициирована авторизация через ACS банка-эмитента
        // 6 - авторизация отклонена
        
        if ($status_data['orderStatus'] == 2) {
            // Оплата прошла успешно
            $order->payment_complete($status_data['orderId']);
            $order->add_order_note(__('Платеж успешно завершен через Сбербанк', 'sberbank-payment-gateway'));
            
            // Сохраняем дополнительные данные
            if (isset($status_data['cardAuthInfo'])) {
                $card_info = $status_data['cardAuthInfo'];
                $order->update_meta_data('_sberbank_pan', $card_info['pan']);
                $order->update_meta_data('_sberbank_cardholder', $card_info['cardholderName']);
                $order->save();
            }
            
        } elseif ($status_data['orderStatus'] == 3 || $status_data['orderStatus'] == 6) {
            // Оплата не прошла
            $order->update_status('failed', __('Платеж не прошел через Сбербанк', 'sberbank-payment-gateway'));
        }
    }
    
    private function get_gateway() {
        $gateways = WC()->payment_gateways->payment_gateways();
        return $gateways['sberbank_payment_gateway'];
    }
}