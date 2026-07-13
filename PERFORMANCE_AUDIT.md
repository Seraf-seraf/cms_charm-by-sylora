# Аудит производительности

Дата: 2026-07-13

## Что проверено

- Минифицированные production-assets темы.
- Наличие WebP/AVIF pipeline с fallback через `<picture>`.
- Наличие `width`/`height` у runtime-изображений Twig-шаблонов.
- Попытка локального измерения размеров главной, каталога и товара.

## Результат локальной проверки

Локальная проверка выполнена через PHP-сервер и Docker MySQL с базой OpenCart.
Тестовый товар: `product_id=28`.

Проверка доступности страниц:

```bash
php -S 127.0.0.1:8080 -t upload
curl -sS -L -o /tmp/sylora-home.html -w '%{http_code} %{size_download} %{time_total}\n' http://127.0.0.1:8080/
```

Фактические HTML-размеры на `http://127.0.0.1:8000`:

| Страница | HTTP | HTML |
| --- | ---: | ---: |
| Главная | 200 | 29.7 KB |
| Каталог/поиск | 200 | 59.8 KB |
| Товар `product_id=28` | 200 | 29.8 KB |

Lighthouse mobile, performance category:

| Страница | Score | FCP | LCP | TBT | CLS | Transfer |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| Главная | 76 | 3.5 s | 3.8 s | 220 ms | 0 | 488 KB |
| Каталог/поиск | 72 | 3.8 s | 4.2 s | 190 ms | 0 | 520 KB |
| Товар `product_id=28` | 79 | 3.7 s | 4.0 s | 60 ms | 0.01 | 501 KB |

PageSpeed Insights не запускался на локальном `127.0.0.1`, потому что сервис
Google не может открыть локальный URL. Для PSI нужен production/staging URL.

## Воспроизводимые команды

Статический performance-аудит без БД:

```bash
php tools/performance-audit.php
```

Аудит размеров HTML на рабочей витрине:

```bash
PERFORMANCE_BASE_URL=https://example.com php tools/performance-audit.php
```

Если в тестовой базе нет `product_id=1`, передайте существующий путь товара:

```bash
PERFORMANCE_BASE_URL=https://example.com \
PERFORMANCE_PRODUCT_PATH="/index.php?route=product/product&product_id=28" \
php tools/performance-audit.php
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
