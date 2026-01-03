# Ops Log (Market Platform)

Журнал изменений по эксплуатации/инфраструктуре. Каждый сеанс — отдельная запись: дата, что сделали, где изменили, команды/проверки и результат.

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
