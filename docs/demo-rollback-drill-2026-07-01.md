# Rollback drill для demo/public demo flags

Этот drill нужен перед включением public demo на prod и после любого изменения demo-флагов. Он не требует миграций и не должен менять данные.

## Цель

- Убедиться, что demo-контур можно быстро вернуть в безопасное состояние.
- Проверить, что public demo не связан с live 1C или внешними интеграциями.
- Не публиковать общий пароль и не включать risky-флаги без отдельного решения.

## Базовое безопасное состояние

На prod безопасный baseline:

```env
DEMO_PILOT_PUBLIC_LOGIN_ENABLED=false
DEMO_PILOT_EXTERNAL_INTEGRATIONS_ENABLED=false
DEMO_PILOT_ALLOW_PROD_WRITES=false
DEMO_PILOT_PROVISION_ENABLED=false
DEMO_PILOT_RESET_ENABLED=false
```

`DEMO_PILOT_ENABLED` может оставаться выключенным или включенным только как общий gate для уже подготовленного demo-контура. Сам по себе он не должен разрешать записи без дополнительных флагов.

## Read-only audit

Перед любым включением или отключением demo-флагов выполнить:

```bash
php artisan demo:flags-audit
```

Команда:

- не печатает пароль;
- не пишет в БД;
- показывает resolved email и redirect для public demo;
- возвращает failure, если public demo совмещен с внешними интеграциями или production demo writes.

## Drill на staging

1. Убедиться, что дерево чистое и код соответствует `origin/main`.
2. Проверить `php artisan demo:flags-audit`.
3. Включить public demo только на staging.
4. Выполнить smoke:
   - `/demo` открывается;
   - вход ведет в синтетический demo market;
   - карта, арендаторы, договоры и заявки открываются;
   - live 1C/webhook-интеграций у demo market нет;
   - staff rail, quick chat и live feed не смешивают сотрудников demo и боевого рынка;
   - выход из кабинета арендатора возвращает в админку без 419 и сохраняет market context исходной страницы.
5. Отключить public demo flag.
6. Проверить, что `/demo` больше не выполняет public login.
7. Повторить `php artisan demo:flags-audit`.

## Emergency rollback на prod

Если после включения public demo на prod возникла проблема:

1. Выключить:
   - `DEMO_PILOT_PUBLIC_LOGIN_ENABLED=false`
   - `DEMO_PILOT_EXTERNAL_INTEGRATIONS_ENABLED=false`
   - `DEMO_PILOT_ALLOW_PROD_WRITES=false`
   - `DEMO_PILOT_PROVISION_ENABLED=false`
   - `DEMO_PILOT_RESET_ENABLED=false`
2. Выполнить:

```bash
php artisan config:clear
php artisan optimize:clear
php artisan demo:flags-audit
```

3. Smoke:
   - обычный `/admin` открывается;
   - `/demo` не выполняет public login;
   - боевой рынок id=1 открывается;
   - staff rail, quick chat и live feed показывают сотрудников выбранного боевого рынка, а не demo;
   - вход/выход из кабинета арендатора не приводит к 419;
   - Horizon running;
   - ошибок в текущем deploy smoke нет.

## Когда можно включать public demo на prod

Только отдельным решением после staging drill:

- demo market содержит только синтетические данные;
- владелец видит заявки и получает уведомления;
- общий пароль не публикуется;
- `DEMO_PILOT_EXTERNAL_INTEGRATIONS_ENABLED=false`;
- `DEMO_PILOT_ALLOW_PROD_WRITES=false`;
- rollback-команды проверены на staging.
