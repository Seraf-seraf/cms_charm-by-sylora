# Внешние расширения

`yandex-metrica-consent` — локальный checkout отдельного репозитория [Seraf-seraf/opencart-yandex-metrica-consent](https://github.com/Seraf-seraf/opencart-yandex-metrica-consent) с устанавливаемым расширением OpenCart. Каталог намеренно исключен из родительского репозитория; production-копия файлов расширения хранится в `upload` основного проекта.

Проверить соответствие production-копии исходникам расширения:

```bash
php tools/sync-yandex-metrica-extension.php --check
```

Обновить production-копию после изменения расширения:

```bash
php tools/sync-yandex-metrica-extension.php
```
