-- Переключает существующую БД OpenCart на единственный язык ru-ru.
-- Выполнять под пользователем БД с правами UPDATE/INSERT.

START TRANSACTION;

UPDATE `language`
SET
  `name` = 'Русский',
  `code` = 'ru-ru',
  `locale` = 'ru_RU.UTF-8,ru_RU,ru-ru,russian',
  `status` = 1
WHERE `code` <> 'ru-ru'
ORDER BY `language_id`
LIMIT 1;

INSERT INTO `language` (`name`, `code`, `locale`, `sort_order`, `status`)
SELECT 'Русский', 'ru-ru', 'ru_RU.UTF-8,ru_RU,ru-ru,russian', 1, 1
WHERE NOT EXISTS (
  SELECT 1
  FROM `language`
  WHERE `code` = 'ru-ru'
);

UPDATE `setting`
SET `value` = 'ru-ru'
WHERE `key` IN ('config_language', 'config_admin_language');

COMMIT;
