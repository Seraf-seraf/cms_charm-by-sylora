# Доменные тесты проекта

Каждая предметная область хранит тесткейсы в собственной поддиректории. Тесты нельзя добавлять непосредственно в `tests/phpunit/domen`.

Текущие домены:

- `support/TestBootstrap.php` отвечает за инициализацию и восстановление тестового окружения.
- `support/BrowserTestCase.php` отвечает только за lifecycle PHPUnit и запуск browser-сценария.
- `analytics` — согласие на аналитику, cookie-плашка и загрузка счетчиков; доменные runner и объект результата находятся в `analytics/support`.

Запуск всех доменных тестов:

```bash
./upload/system/storage/vendor/bin/phpunit tests/phpunit/domen
```
