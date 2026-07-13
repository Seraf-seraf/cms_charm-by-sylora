<?php
// Heading
$_['heading_title']               = 'Payment Service';

// Text
$_['text_extension']              = 'Расширения';
$_['text_success']                = 'Настройки Payment Service обновлены!';
$_['text_edit']                   = 'Настройки Payment Service';
$_['text_enabled']                = 'Включено';
$_['text_disabled']               = 'Отключено';
$_['text_all_zones']              = 'Все зоны';

// Entry
$_['entry_api_url']               = 'URL сервиса payment';
$_['entry_api_key']               = 'API key мерчанта';
$_['entry_shared_secret']         = 'Shared secret';
$_['entry_callback_url']          = 'Callback URL';
$_['entry_success_url']           = 'Success URL';
$_['entry_fail_url']              = 'Fail URL';
$_['entry_total']                 = 'Минимальная сумма';
$_['entry_pending_status']        = 'Статус ожидания';
$_['entry_success_status']        = 'Статус успешной оплаты';
$_['entry_failed_status']         = 'Статус ошибки';
$_['entry_canceled_status']       = 'Статус отмены';
$_['entry_refunded_status']       = 'Статус возврата';
$_['entry_geo_zone']              = 'Геозона';
$_['entry_status']                = 'Статус';
$_['entry_sort_order']            = 'Порядок сортировки';
$_['entry_timestamp_skew']        = 'Допуск timestamp, сек.';

// Help
$_['help_api_url']                = 'Production: https://pay.charm-by-sylora.ru. HTTPS обязателен, кроме локальной разработки. Путь /api/v1/payments добавляется автоматически.';
$_['help_callback_url']           = 'Этот URL нужно указать в merchants.callback_url сервиса payment.';
$_['help_success_url']            = 'Этот URL нужно указать в merchants.success_url сервиса payment.';
$_['help_fail_url']               = 'Этот URL нужно указать в merchants.fail_url сервиса payment.';
$_['help_shared_secret']           = 'Укажите ссылку на переменную окружения, например env:PAYMENT_SERVICE_SHARED_SECRET. Оставьте поле пустым, чтобы сохранить текущую ссылку.';
$_['help_total']                  = 'Минимальная сумма заказа, при которой способ оплаты доступен.';
$_['help_timestamp_skew']         = 'Максимальное расхождение времени при проверке подписи callback.';

// Error
$_['error_permission']            = 'У вас нет прав для изменения Payment Service!';
$_['error_api_url']               = 'Укажите корректный HTTPS URL payment-service. HTTP разрешен только для localhost.';
$_['error_api_key']               = 'Укажите API key мерчанта как ссылку на переменную окружения, например env:PAYMENT_SERVICE_API_KEY.';
$_['error_shared_secret']         = 'Укажите shared secret как ссылку на переменную окружения, например env:PAYMENT_SERVICE_SHARED_SECRET.';
