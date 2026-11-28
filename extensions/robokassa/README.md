# Robokassa для OpenCart 3.0.5.0

Пакет основан на официальном модуле Robokassa с правками под оплату в рублях на стороне Robokassa. Карты и СБП включаются в кабинете. «Робочеки СМЗ» подключаются через разрешение в «Моём налоге». В форму оплаты передаётся подписанный состав чека из сохранённых позиций заказа, включая платную доставку. [Официальный модуль](https://github.com/robokassa/cms-opencart) · [Подключение чеков НПД](https://robokassa.com/online-check/robocheck-smz/) · [Формат Receipt](https://docs.robokassa.ru/ru/fiscalization).

## Сборка

Выполнить из корня магазина. Команды соберут пакет в новой временной директории.

```bash
set -euo pipefail
patch_file="$PWD/extensions/robokassa/opencart3-npd.patch"
build_dir="$(mktemp -d)"
upstream="$build_dir/upstream"
git clone https://github.com/robokassa/cms-opencart.git "$upstream"
git -C "$upstream" checkout --detach 14247bae5a1c023246794d470835b81a70afe1ee
git -C "$upstream" apply --check "$patch_file"
git -C "$upstream" apply "$patch_file"

cd "$upstream/OpenCart 3.x"
cp ../LICENSE.txt LICENSE.txt
zip -q -r "$build_dir/robokassa-npd.ocmod.zip" upload LICENSE.txt -x \
  '*/robokassa_credit.*' '*/robokassa_mokka.*' '*/robokassa_podeli.*' \
  '*/robokassa_sbp.*' '*/robokassa_widget.*' '*/robokassa_yandex_split.*' \
  '*/robokassa_checkout_graph.js' '*/robokassa_product_widget_mount.js'
printf '%s\n' "$build_dir/robokassa-npd.ocmod.zip"
```

## Настройка на тестовом магазине

1. Загрузить ZIP через установщик дополнений и установить платёжное расширение Robokassa. Установить обновление магазина с разрешением CSP для формы оплаты.
2. В кабинете Robokassa указать MD5 и настроить HTTPS-адреса на домене тестового магазина.

   | URL | Маршрут в `index.php?route=` | Метод |
   | --- | --- | --- |
   | Result URL | `extension/payment/robokassa/result` | POST |
   | Success URL | `extension/payment/robokassa/success` | GET |
   | Fail URL | `extension/payment/robokassa/fail` | GET |

3. В OpenCart указать идентификатор магазина, пароли № 1 и № 2 для тестового режима, включить тестовый приём платежей и выбрать статус «Оплачен». Его нужно включить в список статусов обработки заказов OpenCart.
4. Проверить оплату, отмену, повтор Result URL и совпадение суммы. После согласованного полного контрольного возврата аннулировать связанный чек с причиной возврата и проверить результат в «Моём налоге». [Уведомления Robokassa](https://docs.robokassa.ru/ru/notifications-and-redirects) · [Возврат и аннулирование чека НПД](https://robokassa.com/online-check/robocheck-smz/).

При общем запуске сменить способ оплаты для новых заказов. Настройки и маршруты прежнего платёжного модуля нужны для обработки исходных платежей и возвратов по ним.
