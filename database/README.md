# Database Migrations

Миграции запускаются вручную из корня проекта:

```bash
php database/migrate.php
```

Runner выполняет файлы из `database/migrations` в порядке имени файла и
записывает успешные выполнения в таблицу `migrations`:

- `migration` - имя файла миграции;
- `batch` - номер запуска, как в Laravel.

Поддерживаются `.sql` и `.php` файлы. Новые миграции нужно называть так, чтобы
лексикографический порядок отражал зависимости:

```text
YYYY_MM_DD_000001_short_description.php
YYYY_MM_DD_000002_next_step.sql
```

Первой миграцией является
`0000_00_00_000000_create_migrations_table.sql`; она создает таблицу учета.
