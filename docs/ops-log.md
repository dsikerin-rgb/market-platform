# Ops Log (Market Platform)

Журнал изменений по эксплуатации/инфраструктуре. Каждый сеанс — отдельная запись: дата, что сделали, где изменили, команды/проверки и результат.

---

## 2026-03-17 — Стратегическое решение о привязке договоров к местам

**Контекст:** после применения safe auto-link (6 bridge + 18 number) зафиксировали текущую стратегию и то, что ПОКА НЕ внедряем.

**Не внедряем сейчас:**

* **Группы торговых мест** как обязательный слой привязки договоров
* **Временные слои / исторические составы мест** (когда место меняло границы/номер)
* **Массовый аудит всех бумажных договоров** для принудительной привязки

**Текущая рабочая стратегия:**

Используем **простую модель привязки** с тремя категориями:

1. **Точная привязка к месту** (exact link) — договор однозначно матчится на место по номеру/коду
2. **Привязка только к арендатору** (tenant fallback) — договор привязан к арендатору, но не к конкретному месту
3. **Спорный кейс / требует ручного разбора** — ambiguous, multi_primary, no_match

**Правила:**

* Safe auto-link применяем **только для high-confidence кейсов** (auto_bridge, auto_number)
* Всё **неоднозначное автоматически не привязываем** (оставляем на ручной разбор)

**Причина решения:**

* Раздел групп мест ещё не доведён до конца
* Временные слои сильно усложнят проект (историчность, версионирование, миграции)
* Эффект сейчас не оправдывает стоимость внедрения (большинство кейсов покрывается простой моделью)
* Сначала нужно **стабилизировать базовую модель** и накопить статистику спорных случаев

**Для карты зафиксировали:**

* **exact link** (space-exact) → показываем долг по месту (статус из contract_debts по этому месту)
* **tenant_fallback** → показываем статус арендатора (когда нет точной связи с местом)
* **Спорные случаи** не должны насильно превращаться в "точный долг по месту"

**Результат:**

* Стратегия задокументирована в `docs/ops.md` (раздел 0.4)
* Команда понимает, какие кейсы автоматим, какие оставляем на ручной разбор

---

## 2026-03-17 — Safe auto-link tenant_contracts -> market_spaces на staging и prod

**Контекст:** применение safe auto-link для привязки договоров к местам через `contracts:link-spaces`.

**Staging:**

* Backup перед apply:
  * `staging_before_contract_space_apply_2026-03-17_16-27-23.dump` (5.3M)
* Применено через `php artisan contracts:link-spaces --market=1 --apply`:
  * 6 auto_bridge (safe bridge: 1 primary + 1 exact place)
  * 0 auto_number (на staging не было number-matches)
* Число договоров без места уменьшилось с 1059 до 1053
* UI-проверка подтверждена на договорах:
  * contract 327 → auto_bridge → space 33
  * contract 1151 → auto_bridge → space 118

**Production:**

* Backup перед apply:
  * `prod_before_contract_space_apply_2026-03-17_20-32-57.dump`
* Применено через `php artisan contracts:link-spaces --market=1 --apply`:
  * 6 auto_bridge (те же 6 safe bridge-кейсов)
  * 18 auto_number (tenant 378, Телега 1-4)
* Число договоров без места уменьшилось с 1061 до 1037 (на 24)
* Аудит 18 auto_number подтвердил:
  * все 18 = `primary_contract` (classifier)
  * все 18 относятся к одному арендатору: tenant_id=378 (Раджабов Шухратджон Содикович)
  * все 18 привязаны к одному месту: Телега 1-4, space_id=139
* UI-проверка на prod подтверждена:
  * contract 650 → auto_number → space 139 (Телега 1-4)
  * contract 327 → auto_bridge → space 33
* Rollback не потребовался

**Команды/проверки:**

* Preview перед apply: `php artisan contracts:link-spaces --market=1`
* Apply: `php artisan contracts:link-spaces --market=1 --apply`
* Post-check: `php artisan contracts:link-spaces --market=1`
* Точечная проверка contract_id:
  ```php
  DB::table('tenant_contracts')
    ->whereIn('id', [327, 536, 949, 1151, 1181, 1380])
    ->get(['id', 'tenant_id', 'market_space_id', 'space_mapping_mode']);
  ```

**Результат:**

* Safe auto-link отработал корректно на staging и prod
* Все 6 bridge + 18 number применены без ошибок
* UI подтверждает привязку на карте и в карточках договоров

---

## 2025-12-23 — Добавили ops-runbook в репозиторий

**Контекст:** фиксация эксплуатационных знаний в кодовой базе.

**Изменения**

* README: добавлены ссылки на ops-документацию, уточнена политика релизов.
* `docs/ops.md`: добавлен runbook (окружения/пути, Redis, webhook, диагностика).

**Результат**

* Документация по эксплуатации доступна в репозитории и обновляется через PR.

**Команды/проверки**

* `git diff --check`
* `git diff --cached --check`

---

## 2026-01-03 — Horizon: доступ только super-admin, выравнивание staging/prod, запуск systemd в prod

**Контекст:** подготовка эксплуатационного контура очередей и мониторинга, унификация Horizon между local/staging/prod.

**Изменения (код)**

* PR #29 (merge в `main`):

  * добавлен пункт user menu Filament “Horizon (очереди)” (видимость только для `super-admin`);
  * унифицирована авторизация Horizon UI во всех окружениях через `Horizon::auth()` + gate `viewHorizon` (только `super-admin`, остальные получают 403);
  * `config/horizon.php`: Horizon под `/admin/horizon`, middleware `web, auth`, `HORIZON_PREFIX` изолирован через `REDIS_PREFIX`.

**Изменения (staging)**

* Staging обновлён до `c5f9ab6` (Merge PR #29).
* Horizon работает как systemd-сервис `market-staging-horizon.service` под `www-data`.
* Диагностика: исправляли запуск git-операций под правильным пользователем (для записи в `.git/FETCH_HEAD` использовали `sudo -u www-data ...`).

**Изменения (production)**

* Перед обновлением сделан бэкап SQLite:

  * `/var/www/market/current/database/backups/database_2026-01-03_180644.sqlite`
* Prod обновлён до `c5f9ab6` вручную под `www-data`:

  * `git reset --hard origin/main`
  * `composer install --no-dev --optimize-autoloader`
  * `php artisan optimize:clear`
  * `php artisan migrate --force` (миграций не было)
  * `php artisan filament:upgrade`
* Исправление прав git-метаданных (после прежних запусков git под разными пользователями):

  * `chown` для `.git/FETCH_HEAD` и `.git/logs/...`
* Horizon в prod запущен как systemd-сервис:

  * unit: `market-horizon.service`
  * состояние: `enabled`, `active`
  * `php artisan horizon:status` → `running`

**Команды/проверки (ключевое)**

* Версия кода:

  * `git log -1 --oneline`
* Staging: обновление tracking refs и деплой:

  * `sudo -u www-data git fetch origin`
  * `/usr/local/bin/deploy_market_staging.sh`
* Prod: ручной деплой под `www-data` (см. runbook) + контроль Horizon:

  * `php artisan horizon:status`
  * `systemctl is-enabled market-horizon.service`
  * `systemctl is-active market-horizon.service`

**Результат**

* Horizon доступен из админки и корректно ограничен по роли `super-admin` (local/staging/prod).
* Staging и prod синхронизированы по версии `c5f9ab6`.
* Horizon в prod работает как systemd-сервис и готов к эксплуатации.
