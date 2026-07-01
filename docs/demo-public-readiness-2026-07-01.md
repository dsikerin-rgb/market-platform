# Public demo readiness checklist

Дата: 2026-07-01

Цель: проверить, что публичный вход в демо через `/demo` можно включать безопасно, без общего пароля, без записи демо-данных и без внешних интеграций.

## Базовое правило

`demo:public-readiness` только читает конфигурацию и БД. Команда не создаёт, не изменяет и не удаляет рынки, пользователей, договоры, места, начисления, файлы или интеграции.

## Проверки

Команда проверяет:

- демо-рынок найден по `DEMO_PILOT_MARKET_SLUG`;
- рынок активен и помечен как synthetic demo source;
- публичный демо-пользователь найден по `DEMO_PILOT_PUBLIC_LOGIN_EMAIL`;
- пользователь привязан к демо-рынку;
- пользователь имеет роль `market-owner-director`, `market-admin` или `demo-market-admin`;
- source-маркер пользователя совместим с демо-контуром;
- `DEMO_PILOT_EXTERNAL_INTEGRATIONS_ENABLED=false`;
- `DEMO_PILOT_ALLOW_PROD_WRITES=false`;
- `DEMO_PILOT_PROVISION_ENABLED=false`;
- `DEMO_PILOT_RESET_ENABLED=false`;
- пароль демо-доступа, если задан, не нарушает минимальные требования, но само значение не выводится.

## Staging flow

1. Деплой кода с флагами public demo off.
2. Выполнить:

```bash
php artisan demo:flags-audit
php artisan demo:public-readiness
```

3. Если `demo:public-readiness` возвращает `ready_to_enable`, можно отдельно включить `DEMO_PILOT_PUBLIC_LOGIN_ENABLED=true` на staging.
4. После включения выполнить:

```bash
php artisan optimize:clear
php artisan demo:flags-audit
php artisan demo:public-readiness
```

5. Smoke: открыть `/demo`, убедиться, что вход идёт под synthetic demo director и редирект ведёт на демо-карту.
6. Smoke админской сессии: под super-admin открыть боевой рынок, затем `/demo`; аккаунт super-admin не должен замениться demo-пользователем, а выбранный рынок должен меняться только через market context.
7. Smoke правой панели: на странице боевого рынка staff rail, quick chat и live feed показывают сотрудников боевого рынка; на странице demo-рынка показывают только сотрудников demo-рынка.
8. Smoke кабинета арендатора: из админки открыть арендатора, войти в его кабинет, нажать выход; возврат должен быть в админку без 419, с тем же рынком и без смешивания сотрудников разных рынков.

## Prod flow

Prod-флаг `DEMO_PILOT_PUBLIC_LOGIN_ENABLED=true` включается только отдельным решением. Перед включением:

```bash
php artisan demo:flags-audit
php artisan demo:public-readiness
```

После включения:

```bash
php artisan optimize:clear
php artisan demo:flags-audit
php artisan demo:public-readiness
```

Smoke: открыть `/demo` в отдельной сессии, проверить редирект, демо-рынок, карту, dashboard и отсутствие доступа к боевому рынку.

Дополнительный smoke под super-admin обязателен после включения public demo:

- открыть боевой рынок в админке и проверить, что правая панель сотрудников относится к этому рынку;
- открыть demo-рынок через `/demo`, проверить demo-карту и demo-сотрудников;
- вернуться в боевой рынок и убедиться, что staff rail, quick chat и live feed не остались в demo context;
- из карточки арендатора войти в кабинет арендатора и выйти обратно; возврат должен быть без 419 и с сохранением market context исходной админской страницы.

## Rollback

Минимальный rollback публичного демо:

```bash
DEMO_PILOT_PUBLIC_LOGIN_ENABLED=false
php artisan optimize:clear
php artisan demo:flags-audit
```

Если нужен аварийный останов демо-контура, дополнительно держать выключенными:

```bash
DEMO_PILOT_PROVISION_ENABLED=false
DEMO_PILOT_RESET_ENABLED=false
DEMO_PILOT_ALLOW_PROD_WRITES=false
DEMO_PILOT_EXTERNAL_INTEGRATIONS_ENABLED=false
```
