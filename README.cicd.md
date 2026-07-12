# CI/CD

Проект использует GitHub Actions:

- CI: PHP lint, PHPStan, проверка production-assets и code style на ветке `main`.
- CD: после успешного CI отдельным SSH-этапом применяются миграции, собираются production-assets, затем выполняется деплой.

## GitHub Secrets

Добавь эти secrets в GitHub repository settings:

- `SSH_HOST` - IP или hostname сервера.
- `SSH_USER` - SSH-пользователь на сервере.
- `SSH_PASSWORD` - пароль SSH-пользователя.
- `SSH_PORT` - SSH-порт, обычно `22`.
- `DEPLOY_PATH` - абсолютный путь к корню проекта на сервере.

## Настройка сервера

Workflow ожидает, что `DEPLOY_PATH` уже является git-клоном этого репозитория.

Пример первичной настройки:

```bash
cd /path/to
git clone git@github.com:Seraf-seraf/cms_charm-by-sylora.git site
cd site
composer install --no-dev --prefer-dist --optimize-autoloader
php tools/build-assets.php
```

Сервер должен уметь получать код из GitHub:

```bash
cd /path/to/site
git fetch origin main
```

OpenCart runtime-файлы игнорируются git: локальные конфиги, cache, logs,
sessions и Composer `vendor`.

## Миграции БД

Миграции не запускаются OpenCart автоматически. В GitHub Actions они вынесены
в отдельный этап перед деплоем. Вручную их можно выполнить из корня проекта:

```bash
php database/migrate.php
```

Выполненные файлы записываются в таблицу `migrations`, повторный запуск
пропускает уже примененные миграции.

## Production-assets

CSS/JS темы минифицируются PHP-сборщиком:

```bash
php tools/build-assets.php
```

CI запускает `php tools/build-assets.php --check` и падает, если
`stylesheet.min.css` или `common.min.js` не соответствуют исходникам.
