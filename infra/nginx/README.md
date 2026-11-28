# Nginx

Конфигурация рассчитана на OpenCart, где публичный document root указывает на
директорию `upload`. Она предназначена для самостоятельного Nginx, а не для
схемы Nginx → Apache.

## Предварительные условия

До включения HTTPS server blocks выпусти сертификат Let's Encrypt. При первичной
установке это можно сделать через временный HTTP-сайт или `certbot --standalone`.
Также создай каталог для последующих ACME challenge:

```bash
sudo mkdir -p /var/www/certbot/.well-known/acme-challenge
```

## Установка

```bash
sudo cp infra/nginx/charm-by-sylora.conf /etc/nginx/sites-available/charm-by-sylora.conf
sudo ln -s /etc/nginx/sites-available/charm-by-sylora.conf /etc/nginx/sites-enabled/charm-by-sylora.conf
sudo nginx -t
sudo systemctl reload nginx
```

Шаблон зафиксирован для основного домена `charm-by-sylora.ru` и ожидает:

- document root `/var/www/charm-by-sylora/upload`;
- PHP-FPM socket `/run/php/php8.5-fpm.sock`;
- сертификат Let's Encrypt в `/etc/letsencrypt/live/charm-by-sylora.ru/`;
- webroot ACME challenge `/var/www/certbot`.

Если пути на production отличаются, измени только соответствующие директивы перед
`nginx -t`. HTTP и `www` перенаправляются кодом 301 на
`https://charm-by-sylora.ru`; основной HTTPS vhost обслуживает OpenCart.

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
