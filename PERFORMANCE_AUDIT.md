# Аудит производительности

Дата: 2026-07-13

## Что проверено

- Минифицированные production-assets темы.
- Наличие WebP/AVIF pipeline с fallback через `<picture>`.
- Наличие `width`/`height` у runtime-изображений Twig-шаблонов.
- Попытка локального измерения размеров главной, каталога и товара.

## Результат локальной проверки

Локальный PHP-сервер стартует, но витрина возвращает HTTP 500, потому что
локальная БД из `upload/config.php` недоступна. Поэтому PageSpeed Insights и
Lighthouse по фактическим страницам нужно запускать на production/staging URL
или после восстановления локальной БД.

Проверка доступности:

```bash
php -S 127.0.0.1:8080 -t upload
curl -sS -L -o /tmp/sylora-home.html -w '%{http_code} %{size_download} %{time_total}\n' http://127.0.0.1:8080/
```

Фактический результат локально: `500 0`.

## Воспроизводимые команды

Статический performance-аудит без БД:

```bash
php tools/performance-audit.php
```

Аудит размеров HTML на рабочей витрине:

```bash
PERFORMANCE_BASE_URL=https://example.com php tools/performance-audit.php
```

Lighthouse для мобильной скорости, CLS и размера страниц:

```bash
npx --yes lighthouse https://example.com/ \
  --preset=desktop \
  --chrome-flags="--headless --no-sandbox" \
  --output=html \
  --output-path=storage/logs/lighthouse-home.html

npx --yes lighthouse https://example.com/index.php?route=product/search \
  --chrome-flags="--headless --no-sandbox" \
  --output=html \
  --output-path=storage/logs/lighthouse-catalog.html

npx --yes lighthouse "https://example.com/index.php?route=product/product&product_id=1" \
  --chrome-flags="--headless --no-sandbox" \
  --output=html \
  --output-path=storage/logs/lighthouse-product.html
```

PageSpeed Insights:

```bash
npx --yes psi https://example.com/ --strategy=mobile
npx --yes psi https://example.com/index.php?route=product/search --strategy=mobile
npx --yes psi "https://example.com/index.php?route=product/product&product_id=1" --strategy=mobile
```

## Критерии приемки

- Главная, каталог и товар отдают HTTP 200.
- HTML главной до 160 KB, каталога до 180 KB, товара до 220 KB без gzip.
- Lighthouse mobile: без критичного CLS, целевой CLS `< 0.1`.
- PageSpeed Insights mobile: проверить LCP/CLS/TBT после наполнения реальными
  production-изображениями и включенного серверного кеширования.
