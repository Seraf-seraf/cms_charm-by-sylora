# Nginx

Конфигурация рассчитана на OpenCart, где публичный document root указывает на
директорию `upload`.

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
