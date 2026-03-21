# Operations Runbook

**Market Platform** — цифровая системы управления рынком.

Документ: окружения и пути, домены/HTTPS, Redis-изоляция, Horizon (очереди), staging deploy webhook и диагностика типововых проблем.

Секреты и пароли в репозитории не храним — только в `.env` на сервере.

---

## 0) Safe auto-link tenant_contracts -> market_spaces

**Назначение:** автоматическая привязка договоров к местам при импорте или массовом apply.

### 0.1) Правила автопривязки

* Новые договоры без места могут автопривязываться сразу при импорте
* Автопривязка разрешена **только для high-confidence кейсов**
* **Safe rules:**
  * `auto_bridge` — tenant имеет ровно 1 primary contract без места + ровно 1 exact place bridge из accruals (source_place_code → market_space)
  * `auto_number` — contract.number exact/normalized содержит ровно 1 code/number места арендатора
* Автопривязка **только для primary_contract**
* **Non-primary** автоматически не привязываем (supplemental, service, penalty, placeholder, unknown)
* **Ambiguous / no_match / multi_primary** автоматически не привязываем

### 0.2) Контроль-барьер перед apply

Перед массовым apply на окружении:

1. Показать DB target:
   ```bash
   php artisan tinker --execute="
   echo 'db_connection='.config('database.default').PHP_EOL;
   echo 'db_host='.config('database.connections.'.config('database.default').'.host').PHP_EOL;
   echo 'db_database='.config('database.connections.'.config('database.default').'.database').PHP_EOL;
   "
   ```
2. Показать размер БД:
   ```bash
   php artisan tinker --execute="
   use Illuminate\Support\Facades\DB;
   \$result = DB::select('SELECT pg_size_pretty(pg_database_size(current_database())) as size')[0];
   echo 'database_size='.\$result->size.PHP_EOL;
   "
   ```
3. Сделать backup:
   ```bash
   mkdir -p /var/www/market/backups
   pg_dump -h 127.0.0.1 -U market_user -d market -Fc -f /var/www/market/backups/prod_before_contract_space_apply_\$(date +%Y-%m-%d_%H-%M-%S).dump
   ls -lh /var/www/market/backups | tail -3
   ```
4. Сначала выполнить preview:
   ```bash
   php artisan contracts:link-spaces --market=1
   ```
5. Потом apply:
   ```bash
   php artisan contracts:link-spaces --market=1 --apply
   ```
6. Потом post-check конкретных contract_id:
   ```bash
   php artisan tinker --execute="
   use Illuminate\Support\Facades\DB;
   \$ids = [327, 536, 949, 1151, 1181, 1380];
   \$rows = DB::table('tenant_contracts')->whereIn('id', \$ids)->orderBy('id')->get(['id', 'tenant_id', 'market_space_id', 'space_mapping_mode']);
   foreach (\$rows as \$row) { echo json_encode(\$row, JSON_UNESCAPED_UNICODE).PHP_EOL; }
   "
   ```

### 0.3) Карта: отображение статусов

* **exact link** (space-exact) → показываем долг по месту (статус из contract_debts по этому месту)
* **tenant_fallback** → показываем статус арендатора (когда нет точной связи с местом)
* **Свободные/неактивные места** не красим долгом арендатора (scope=none)
* **Спорные случаи** не должны насильно превращаться в "точный долг по месту"

### 0.4) Стратегическое решение (что ПОКА НЕ внедряем)

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

---

## 1) Окружения и пути

### Production

* Код: `/var/www/market/current`
* URL (прототип): `http://market.176.108.244.218.nip.io`
* DB (SQLite): `/var/www/market/current/database/database.sqlite`
* Redis prefix: `market_prod:`
* Horizon UI: `http://market.176.108.244.218.nip.io/admin/horizon/dashboard`
* Horizon service (systemd): `market-horizon.service`

### Staging

* Код: `/var/www/market-staging/current`
* URL (прототип): `http://market-staging.176.108.244.218.nip.io`
* Доступ: BasicAuth включён (пользователь `staging`, пароль — в htpasswd на сервере)
* DB (SQLite): `/var/www/market-staging/current/database/database.sqlite`
* Redis prefix: `market_staging:`
* Horizon UI: `http://market-staging.176.108.244.218.nip.io/admin/horizon/dashboard`
* Horizon service (systemd): `market-staging-horizon.service`

Важно: staging и prod изолированы по DB и Redis prefix. Пользователи и данные из staging не появляются в prod автоматически.

---

## 2) Домены и HTTPS

### 2.1) Прототип и тестирование

* `nip.io` используем только для прототипа и тестирования.
* Пока нет HTTPS, публичный доступ для арендаторов не открываем.
* Для демонстраций заказчику используем ограниченный доступ (BasicAuth / IP allowlist / VPN).

### 2.2) ВДНХ — боевой контур (после договора)

План: отдельная ВМ на Cloud.ru под ВДНХ, отдельный публичный IP, нормальный домен и HTTPS.

Варианты домена:

* использовать домен заказчика (поддомен вида `app.<их-домен>`), если они готовы настроить DNS;
* зарегистрировать отдельный домен под сервис.

Примечание по инструментам HTTPS:

* стандартный путь — nginx + certbot (Let’s Encrypt);
* альтернатива — Caddy (автосертификаты).

---

## 3) Redis: правила изоляции

Redis на сервере общий для нескольких проектов, поэтому:

1. Всегда задаём `REDIS_PREFIX` для каждого проекта/окружения (даже если Redis «почти не используется»).
2. После правок `.env` выполняем: `php artisan config:clear` (или `php artisan optimize:clear`).
3. В `.env` всегда должен быть перевод строки в конце файла (иначе переменные могут «склеиться»).

### Быстрая проверка префикса

```bash
php artisan tinker --execute="echo config('database.redis.options.prefix').PHP_EOL;"
```

---

## 4) Horizon (очереди): мониторинг и доступ

### 4.0) Production: systemd service (установка)

Horizon в production работает как systemd-сервис `market-horizon.service` под пользователем `www-data`.

Создать/обновить unit и запустить сервис:

```bash
sudo bash -lc 'cat >/etc/systemd/system/market-horizon.service << "EOF"
[Unit]
Description=Market Platform Horizon (prod)
After=network.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/market/current
ExecStart=/usr/bin/php artisan horizon
Restart=always
RestartSec=5
KillSignal=SIGTERM
TimeoutStopSec=30

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable --now market-horizon.service
systemctl is-enabled market-horizon.service
systemctl is-active market-horizon.service
'
```

Проверка логов:

```bash
sudo journalctl -u market-horizon.service -n 200 --no-pager
```

### 4.1) URL и путь

Horizon размещён под `/admin`:

* путь: `/admin/horizon`
* дашборд: `/admin/horizon/dashboard`

Это задано в `config/horizon.php` через:

* `HORIZON_PATH=admin/horizon` (или дефолт в конфиге)

### 4.2) Доступ (безопасность)

* Пункт меню “Horizon (очереди)” в Filament виден **только** `super-admin`.
* Прямой доступ к Horizon UI защищён одинаково во всех окружениях: **только `super-admin`**, остальные получают **403**.
* Защита реализована на уровне Horizon (`Horizon::auth`) + gate `viewHorizon` (центральное правило на модели User).

### 4.3) Изоляция окружений

* `HORIZON_PREFIX` завязан на `REDIS_PREFIX`, чтобы staging/prod не пересекались в одном Redis.
* При смене `REDIS_PREFIX` / `HORIZON_PREFIX` всегда делаем `php artisan optimize:clear`.

### 4.4) Проверки Horizon (staging/prod)

```bash
# приложение (из каталога проекта)
php artisan horizon:status

# systemd (prod)
sudo systemctl is-enabled market-horizon.service
sudo systemctl is-active market-horizon.service
sudo systemctl status market-horizon.service --no-pager

# systemd (staging)
sudo systemctl is-enabled market-staging-horizon.service
sudo systemctl is-active market-staging-horizon.service
sudo systemctl status market-staging-horizon.service --no-pager
```

### 4.5) Перезапуск воркеров после деплоя

После деплоя обязательно:

```bash
php artisan horizon:terminate || true
```

---

## 5) Staging: Nginx + BasicAuth + deploy.php

На staging включён BasicAuth на весь сайт, но для webhook-деплоя сделано исключение:

* В vhost staging есть `location = /deploy.php`, где BasicAuth отключён.
* Запрос к `/deploy.php` обрабатывается php-fpm и запускает деплой.

Ожидаемая логика:

* Любые запросы к сайту → требуют BasicAuth.
* Запрос к `/deploy.php` → без BasicAuth, но с проверкой подписи GitHub (HMAC).

---

## 6) Деплой

### 6.1) Staging (webhook)

* Endpoint: `http://market-staging.176.108.244.218.nip.io/deploy.php`
* Лог: `storage/logs/deploy-market-staging.log`
* Скрипт: `/usr/local/bin/deploy_market_staging.sh` (git/composer/кэши/`horizon:terminate`)

Быстрые проверки после деплоя:

```bash
cd /var/www/market-staging/current && git log -1 --oneline
cd /var/www/market-staging/current && php artisan horizon:status
sudo systemctl is-active market-staging-horizon.service
```

### 6.2) Production (вручную)

На текущем сервере production **нет** отдельного `/usr/local/bin/deploy_market_prod.sh`, поэтому деплой выполняем вручную.

Перед любыми изменениями — бэкап SQLite:

```bash
cd /var/www/market/current
mkdir -p database/backups
cp database/database.sqlite database/backups/database_$(date +%F_%H%M%S).sqlite
```

Деплой (важно выполнять под `www-data`, чтобы не ломать права):

```bash
sudo -u www-data bash -lc '
set -euo pipefail
cd /var/www/market/current
git fetch origin
git reset --hard origin/main
composer install --no-dev --optimize-autoloader
php artisan optimize:clear
php artisan migrate --force
php artisan filament:upgrade
php artisan horizon:terminate || true
git log -1 --oneline
'
```

---

## 7) Чек-лист после деплоя

### 7.1) Версия

```bash
git log -1 --oneline
```

### 7.2) Horizon

```bash
php artisan horizon:status
sudo systemctl is-active market-horizon.service
```

### 7.3) Доступ

* `super-admin`: пункт меню **“Horizon (очереди)”** виден, `/admin/horizon/dashboard` открывается.
* `market-admin`: пункта меню нет, прямой URL `/admin/horizon/dashboard` даёт **403**.

---

## 8) Диагностика и проверки

### 8.1) Проверка окружения и конфигурации

```bash
php artisan about
php artisan optimize:clear
```

### 8.2) Проверка доступа к SQLite и прав

```bash
ls -la /var/www/market-staging/current/storage /var/www/market-staging/current/bootstrap/cache
ls -la /var/www/market-staging/current/database/database.sqlite
```

Ожидаемые требования:

* `storage/` и `bootstrap/cache/` должны быть writable для пользователя веб-сервера (обычно `www-data`).
* `database.sqlite` должен быть writable, иначе будут ошибки «readonly database» и проблемы с кешом/сессиями.

### 8.3) Проверка состояния миграций

```bash
php artisan migrate:status
```

### 8.4) Если “git fetch/pull не работает из-за прав”

Симптомы:

* `cannot open .git/FETCH_HEAD: Permission denied`
* `unable to append to .git/logs/... Permission denied`

Причина: git-команды выполнялись разными пользователями.

Решение (точечно, без `chown -R` всего проекта):

```bash
sudo chown www-data:www-data /var/www/market/current/.git/FETCH_HEAD
sudo chown -R www-data:www-data /var/www/market/current/.git/logs
```

### 8.5) Webhook: “push не деплоит”

Порядок проверки:

1. `storage/logs/deploy-market-staging.log`
2. `/var/log/nginx/error.log`
3. `storage/logs/laravel.log`

---

## 9) HTTPS на сервере 176.108.244.218: диагностика

Цель: понять, что именно «закрыто» и где (внутри сервера или снаружи).

### 9.1) Проверить, слушает ли сервер 80/443

```bash
sudo ss -lntp | grep -E ":(80|443)[[:space:]]"
```

Интерпретация:

* Если 443 не слушается — проблема в конфигурации nginx/сервисе/сертификатах.
* Если 443 слушается — проверяем firewall и внешний доступ.

### 9.2) Проверить firewall на сервере

UFW:

```bash
sudo ufw status verbose
```

nftables:

```bash
sudo nft list ruleset | sed -n "1,200p"
```

iptables:

```bash
sudo iptables -S
sudo iptables -L -n
```

### 9.3) Проверить конфиги nginx и перезагрузку

```bash
sudo nginx -t
sudo systemctl reload nginx
sudo systemctl status nginx --no-pager
```

### 9.4) Посмотреть логи nginx при попытке зайти по HTTPS

```bash
sudo tail -n 200 /var/log/nginx/error.log
sudo tail -n 200 /var/log/nginx/access.log
```

Результат диагностики фиксируем одной записью в `docs/ops-log.md`.

---

## 10) Типовые инциденты и быстрые причины

### 10.1) Переменные `.env` «склеились»

* Симптом: странные пути/значения, например `...database.sqliteREDIS_PREFIX=...`.
* Причина: нет перевода строки в конце `.env`.

### 10.2) SQLite readonly / ошибки записи кеша

* Симптом: нельзя логиниться, кеш/сессии не пишутся, ошибки на запись.
* Причина: права на `storage/`, `bootstrap/cache/`, `database.sqlite`.

### 10.3) Horizon “inactive”

* Симптом: `php artisan horizon:status` → inactive.
* Причина: не запущен systemd-сервис Horizon.
* Решение: `systemctl is-active market-horizon.service` + `journalctl -u market-horizon.service`.

### 10.4) “Кнопки Horizon нет в меню, но ссылка открывается”

* Меню — UX (видимость ограничена `super-admin`).
* Доступ — security (должен быть ограничен gate `viewHorizon`, иначе это баг).

---

## 11) Scheduler + Calendar Task Automation

This project uses Laravel scheduler for calendar automation. Horizon alone is not enough.

Scheduled commands:

* `market:holidays:sync` (daily)
* `market:calendar:generate-sanitary` (daily)
* `market:calendar:generate-tasks` (every 30 minutes, idempotent)
* `market:holidays:notify` (every 30 minutes)
* `tasks:send-reminders` (daily)

Systemd units are versioned in repository:

* `ops/systemd/market-schedule.service`
* `ops/systemd/market-schedule.timer`
* `ops/systemd/market-staging-schedule.service`
* `ops/systemd/market-staging-schedule.timer`

Install/refresh (prod example):

```bash
sudo cp ops/systemd/market-schedule.service /etc/systemd/system/
sudo cp ops/systemd/market-schedule.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now market-schedule.timer
sudo systemctl status market-schedule.timer --no-pager
```

Install/refresh (staging example):

```bash
sudo cp ops/systemd/market-staging-schedule.service /etc/systemd/system/
sudo cp ops/systemd/market-staging-schedule.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now market-staging-schedule.timer
sudo systemctl status market-staging-schedule.timer --no-pager
```

Manual safe bootstrap before first run:

```bash
php artisan market:system-agent:init --all --dry-run
php artisan market:system-agent:init --all --execute
php artisan market:calendar:generate-tasks --dry-run
php artisan market:calendar:generate-tasks
```
