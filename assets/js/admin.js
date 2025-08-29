jQuery(document).ready(function($) {
    // Показываем/скрываем настройки ФФД
    function toggleFFDSettings() {
        if ($('#woocommerce_sberbank_payment_gateway_ffd_enabled').is(':checked')) {
            $('.sberbank-ffd-field').closest('tr').show();
        } else {
            $('.sberbank-ffd-field').closest('tr').hide();
        }
    }
    
    // Инициализация
    toggleFFDSettings();
    $('#woocommerce_sberbank_payment_gateway_ffd_enabled').change(toggleFFDSettings);
    
    // Валидация ИНН
    $('#woocommerce_sberbank_payment_gateway_company_inn').on('blur', function() {
        var inn = $(this).val();
        if (inn && !/^\d{10,12}$/.test(inn)) {
            alert('ИНН должен содержать 10 или 12 цифр');
            $(this).focus();
        }
    });
    
    // Проверка подключения
    $('.sberbank-test-connection').on('click', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var login = $('#woocommerce_sberbank_payment_gateway_merchant_login').val();
        var password = $('#woocommerce_sberbank_payment_gateway_merchant_password').val();
        
        if (!login || !password) {
            alert('Заполните логин и пароль мерчанта');
            return;
        }
        
        $button.text('Проверка...').prop('disabled', true);
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'sberbank_test_connection',
                security: sberbank_admin_params.nonce,
                login: login,
                password: password
            },
            success: function(response) {
                if (response.success) {
                    alert('Подключение успешно!');
                } else {
                    alert('Ошибка подключения: ' + response.data);
                }
            },
            error: function() {
                alert('Ошибка при проверке подключения');
            },
            complete: function() {
                $button.text('Проверить подключение').prop('disabled', false);
            }
        });
    });
    
    // Красивые переключатели
    $('input[type="checkbox"]').each(function() {
        var $checkbox = $(this);
        if (!$checkbox.closest('p').hasClass('checkbox')) {
            $checkbox.wrap('<label class="sberbank-switch">');
            $checkbox.after('<span class="sberbank-slider"></span>');
        }
    });
});
