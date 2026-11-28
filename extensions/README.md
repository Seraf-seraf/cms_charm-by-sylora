# Внешние расширения

`yandex-metrica-consent` — локальный checkout отдельного репозитория [Seraf-seraf/opencart-yandex-metrica-consent](https://github.com/Seraf-seraf/opencart-yandex-metrica-consent) с устанавливаемым расширением OpenCart. Каталог намеренно исключен из родительского репозитория; production-копия файлов расширения хранится в `upload` основного проекта.

`russian-post-delivery` — локальный checkout публичного репозитория [Seraf-seraf/opencart-russian-post-delivery](https://github.com/Seraf-seraf/opencart-russian-post-delivery) с устанавливаемым расширением доставки Почты России. Расширение устанавливается в OpenCart из `.ocmod.zip`; его файлы не входят в основной Git-репозиторий.

Проверить соответствие production-копии исходникам расширения:

```bash
php tools/sync-yandex-metrica-extension.php --check
```

Обновить production-копию после изменения расширения:

```bash
php tools/sync-yandex-metrica-extension.php
```

Собрать установочный архив Почты России:

```bash
cd extensions/russian-post-delivery
zip -r opencart-russian-post-delivery.ocmod.zip upload
```
