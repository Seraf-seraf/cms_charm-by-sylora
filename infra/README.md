# Веб-серверы Charm by Sylora

Production document root проекта — `/var/www/charm-by-sylora/upload`. Корень
репозитория нельзя публиковать: конфигурация, миграции, тесты и `storage` должны
оставаться вне web root.

В репозитории поддерживаются два равноправных варианта:

- [Apache](apache/README.md) — VirtualHost передает PHP в PHP-FPM, а правила ЧПУ
  и кеширования дополняются файлом `upload/.htaccess`;
- [Nginx](nginx/README.md) — все правила маршрутизации и защиты описаны в
  конфигурации server blocks.

Одновременно включать оба варианта напрямую на портах 80/443 не нужно. Если
Nginx используется как reverse proxy перед Apache, конфигурации следует
адаптировать отдельно: готовые шаблоны рассчитаны на самостоятельный веб-сервер.

Оба шаблона используют:

- основной URL `https://charm-by-sylora.ru`;
- 301-редиректы с HTTP и `www`;
- document root `/var/www/charm-by-sylora/upload`;
- PHP-FPM 8.5 через `/run/php/php8.5-fpm.sock`;
- сертификат Let's Encrypt из `/etc/letsencrypt/live/charm-by-sylora.ru/`;
- каталог ACME challenge `/var/www/certbot`.

Перед reload обязательно проверить конфигурацию штатной командой выбранного
веб-сервера. После запуска проверить 301/200/404, ЧПУ, `/robots.txt`,
`/sitemap.xml`, canonical и отсутствие доступа к конфигурационным файлам.
