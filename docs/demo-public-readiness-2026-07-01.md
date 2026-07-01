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
