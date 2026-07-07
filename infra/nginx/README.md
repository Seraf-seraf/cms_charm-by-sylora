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

Перед включением на сервере замени:

- `server_name` на реальные домены.
- `root` на абсолютный путь к `upload` в деплое.
- `fastcgi_pass` на актуальный сокет или upstream PHP-FPM.

HTTPS лучше подключать отдельным certbot/nginx-профилем после проверки HTTP.
