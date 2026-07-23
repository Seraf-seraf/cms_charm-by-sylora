# Внешние расширения

`yandex-metrica-consent` — локальный checkout отдельного репозитория [Seraf-seraf/opencart-yandex-metrica-consent](https://github.com/Seraf-seraf/opencart-yandex-metrica-consent) с устанавливаемым расширением OpenCart. Каталог намеренно исключен из родительского репозитория; production-копия файлов расширения хранится в `upload` основного проекта.

`russian-post-delivery` — локальный checkout публичного репозитория [Seraf-seraf/opencart-russian-post-delivery](https://github.com/Seraf-seraf/opencart-russian-post-delivery) с устанавливаемым расширением доставки Почты России. Production-копия файлов также хранится в `upload` основного проекта.

Проверить соответствие production-копии исходникам расширения:

```bash
php tools/sync-yandex-metrica-extension.php --check
```

Обновить production-копию после изменения расширения:

```bash
php tools/sync-yandex-metrica-extension.php
```

Проверить и обновить production-копию расширения Почты России:

```bash
php tools/sync-russian-post-extension.php --check
php tools/sync-russian-post-extension.php
```
