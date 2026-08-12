# Apache

Конфигурация рассчитана на Apache 2.4 с PHP 8.5 FPM. Публичной является только
директория `upload`; файл `upload/.htaccess` должен присутствовать в деплое.

## Предварительные условия

Нужны модули Apache `rewrite`, `ssl`, `headers`, `expires`, `proxy`,
`proxy_fcgi`, `setenvif` и, при использовании HTTP/2, `http2`:

```bash
sudo a2enmod rewrite ssl headers expires proxy proxy_fcgi setenvif http2
sudo mkdir -p /var/www/certbot/.well-known/acme-challenge
```

До включения HTTPS VirtualHost выпусти сертификат Let's Encrypt. При первичной
установке это можно сделать через временный HTTP-сайт или `certbot --standalone`.
Итоговые файлы должны находиться в:

```text
/etc/letsencrypt/live/charm-by-sylora.ru/fullchain.pem
/etc/letsencrypt/live/charm-by-sylora.ru/privkey.pem
```

## Установка

```bash
sudo cp infra/apache/charm-by-sylora.conf /etc/apache2/sites-available/charm-by-sylora.conf
sudo a2ensite charm-by-sylora.conf
sudo apache2ctl configtest
sudo systemctl reload apache2
```

Шаблон ожидает:

- document root `/var/www/charm-by-sylora/upload`;
- PHP-FPM socket `/run/php/php8.5-fpm.sock`;
- сертификат Let's Encrypt для `charm-by-sylora.ru`;
- ACME webroot `/var/www/certbot`.

Если production-пути отличаются, измени их до `configtest`. VirtualHost на
порту 80 оставляет доступным ACME challenge и перенаправляет остальные запросы
на основной HTTPS-домен. HTTPS-вариант `www` также отвечает постоянным
редиректом. Основной VirtualHost передает PHP в FPM; ЧПУ, sitemap, ограничения
доступа и кеширование статики дополняются правилами `upload/.htaccess`.

## Проверка после установки

```bash
curl -I http://charm-by-sylora.ru/
curl -I https://www.charm-by-sylora.ru/
curl -I https://charm-by-sylora.ru/
curl -I 'https://charm-by-sylora.ru/index.php?route=common/home'
curl -I https://charm-by-sylora.ru/robots.txt
curl -I https://charm-by-sylora.ru/sitemap.xml
curl -I https://charm-by-sylora.ru/config.php
```

Ожидаются соответственно 301, 301, 200, 301 на `https://charm-by-sylora.ru/`,
200, 200 и 403. Для несуществующего публичного URL ожидается 404 со страницей
OpenCart.
