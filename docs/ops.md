**# Operations (Market Platform)**

Этот документ — краткий runbook по эксплуатации проекта: окружения/пути, Redis-изоляция, staging deploy webhook, диагностика типовых проблем.  

Секреты/пароли здесь не храним.

\

---

\

**## Окружения и пути**

\

**### Production**

- Код: `/var/www/market/current`

- URL: `[http://market.176.108.244.218.nip.io`](http://market.176.108.244.218.nip.io`)

- DB (SQLite): `/var/www/market/current/database/database.sqlite`

- Redis prefix: `market_prod:`

\

**### Staging**

- Код: `/var/www/market-staging/current`

- URL: `[http://market-staging.176.108.244.218.nip.io`](http://market-staging.176.108.244.218.nip.io`)

- BasicAuth: включён (пользователь `staging`, пароль — в htpasswd на сервере)

- DB (SQLite): `/var/www/market-staging/current/database/database.sqlite`

- Redis prefix: `market_staging:`

\

Важно: staging и prod изолированы по DB и Redis prefix. Пользователь/данные из staging не появятся в prod автоматически.

\

---

\

**## Redis: правила изоляции**

\

Redis на сервере общий для нескольких проектов, поэтому:

\

1) Всегда задаём `REDIS_PREFIX` для каждого проекта/окружения (даже если Redis “почти не используется”).

2) После правок `.env` выполняем:

   - `php artisan config:clear`

3) В `.env` всегда должен быть перевод строки в конце файла (иначе переменные могут “склеиться”).

\

**### Быстрая проверка префикса**

```bash

php artisan tinker --execute="echo config('database.redis.options.prefix').PHP_EOL;"

```

\

---

\

**## Staging: Nginx + BasicAuth + deploy.php**

\

На staging включён BasicAuth на весь сайт, но для webhook-деплоя сделано исключение:

\

- В vhost staging есть `location = /deploy.php`, где BasicAuth отключён.

- Запрос идёт в php-fpm, чтобы выполнить деплой-скрипт.

\

Ожидаемая логика:

- Любые запросы к сайту → требуют BasicAuth

- Запрос к `/deploy.php` → без BasicAuth, но с проверкой подписи GitHub (HMAC)

\

---

\

**## Staging deploy webhook (GitHub → сервер)**

\

- Endpoint: `[http://market-staging.176.108.244.218.nip.io/deploy.php`](http://market-staging.176.108.244.218.nip.io/deploy.php`)

- Защита: `X-Hub-Signature-256` (HMAC SHA256) по `DEPLOY_WEBHOOK_SECRET` из `.env` staging

- Лог: `storage/logs/deploy-market-staging.log`

- Событие GitHub webhook: `push`

\

Рекомендации:

- Никогда не логировать секреты и полный payload.

- В случае ошибок сначала смотреть лог деплоя и `storage/logs/laravel.log`.

\

---

\

**## Диагностика и проверки**

\

**### Проверка окружения и конфигурации**

```bash

php artisan about

php artisan config:clear

```

\

**### Проверка доступа к SQLite и прав**

```bash

ls -la /var/www/market-staging/current/storage /var/www/market-staging/current/bootstrap/cache

ls -la /var/www/market-staging/current/database/database.sqlite

```

\

Типовые требования:

- `storage/` и `bootstrap/cache/` должны быть writable для пользователя веб-сервера (обычно `www-data`)

- файл `database.sqlite` должен быть writable, иначе будут ошибки “readonly database” / проблемы с кешом/сессиями

\

**### Проверка состояния миграций**

```bash

php artisan migrate:status

```

\

**### Если “после правок .env всё сломалось”**

1) `php artisan config:clear`

2) проверить `.env` на “склейку” строк (особенно последние строки файла)

3) проверить Redis prefix:

```bash

php artisan tinker --execute="echo config('database.redis.options.prefix').PHP_EOL;"

```

\

---

\

**## Типовые инциденты и быстрые причины**

\

**### 1) Переменные **`.env`** “склеились”**

Симптом: странные пути/значения, например `...database.sqliteREDIS_PREFIX=...`  

Причина: нет перевода строки в конце `.env`.

\

**### 2) SQLite readonly / ошибки записи кеша**

Симптом: нельзя логиниться, кеш/сессии не пишутся, ошибки на запись.  

Причина: права на `storage/`, `bootstrap/cache/`, `database.sqlite`.

\

**### 3) Webhook “пинг проходит, push не деплоит”**

Симптом: GitHub сообщает доставку, но код не обновился.

\

Причины:

- неверный `DEPLOY_WEBHOOK_SECRET` или проверка подписи

- `deploy.php` не доступен без BasicAuth

- `deploy.php` не исполняется php-fpm (неверный `location`)

- права на каталог проекта мешают `git pull`/`composer`/записи логов

\

Порядок проверки: `deploy-market-staging.log` → `nginx error.log` → `storage/logs/laravel.log`.