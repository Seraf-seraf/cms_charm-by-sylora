# Payment Service

OpenCart 3.0.5.0 работает с оплатой через отдельное расширение
`payment_service`. Прямой интеграции CMS с T-Bank нет.

## Установка

Миграция регистрирует расширение, создает платежные статусы и таблицы аудита:

```bash
php database/migrate.php
```

Production API URL по умолчанию:

```text
https://pay.charm-by-sylora.ru
```

Миграция намеренно оставляет способ оплаты выключенным и не сохраняет секреты.

## Настройка

В админ-панели OpenCart откройте `Расширения -> Расширения -> Оплата -> Payment Service`.
Для включения нужны данные merchant из payment-service:

- API key;
- shared secret длиной не менее 32 символов;
- merchant с провайдером `tbank`;
- `callback_url`, `success_url` и `fail_url`, показанные на странице настроек расширения.

Сначала сохраните настройки и URL в обеих системах, затем включите расширение.
Способ оплаты доступен только для заказов в RUB с положительной суммой.

## Проверка

```bash
curl -fsS https://pay.charm-by-sylora.ru/api/v1/health
php tests/payment_service_extension_test.php
```

Перед production-приемкой отдельно выполняются успешная и отмененная оплаты,
повторный callback и проверка retry при временно недоступном callback endpoint.
