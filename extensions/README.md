# Внешние расширения

`yandex-metrica-consent` — отдельный Git-репозиторий устанавливаемого расширения OpenCart. Каталог намеренно исключен из родительского репозитория; production-копия файлов расширения хранится в `upload` основного проекта.

Проверить соответствие production-копии исходникам расширения:

```bash
php tools/sync-yandex-metrica-extension.php --check
```

Обновить production-копию после изменения расширения:

```bash
php tools/sync-yandex-metrica-extension.php
```
