# Operations Runbook

**Market Platform** — цифровая система управления рынком.

Документ: окружения и пути, домены/HTTPS, Redis-изоляция, staging deploy webhook и диагностика типовых проблем.

Секреты и пароли в репозитории не храним — только в `.env` на сервере.

---

## 1) Окружения и пути

### Production

* Код: `/var/www/market/current`
* URL (прототип): `http://market.176.108.244.218.nip.io`
* DB (SQLite): `/var/www/market/current/database/database.sqlite`
* Redis prefix: `market_prod:`

### Staging

* Код: `/var/www/market-staging/current`
* URL (прототип): `http://market-staging.176.108.244.218.nip.io`
* Доступ: BasicAuth включён (пользователь `staging`, пароль — в htpasswd на сервере)
* DB (SQLite): `/var/www/market-staging/current/database/database.sqlite`
* Redis prefix: `market_staging:`

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

* Использовать домен заказчика (например, поддомен вида `app.<их-домен>`), если они готовы настроить DNS и согласовать схему.
* Зарегистрировать отдельный домен под сервис (если проще юридически/организационно).

Примечание по инструментам HTTPS:

* Стандартный путь — nginx + certbot (Let’s Encrypt) или альтернативный веб-сервер с автосертификатами (например, Caddy).
* Если нет опыта, начинаем с nginx+certbot как наиболее распространённого варианта.

---

## 3) Redis: правила изоляции

Redis на сервере общий для нескольких проектов, поэтому:

1. Всегда задаём `REDIS_PREFIX` для каждого проекта/окружения (даже если Redis «почти не используется»).
2. После правок `.env` выполняем: `php artisan config:clear`.
3. В `.env` всегда должен быть перевод строки в конце файла (иначе переменные могут «склеиться»).

### Быстрая проверка префикса

```bash
php artisan tinker --execute="echo config('database.redis.options.prefix').PHP_EOL;"
```

---

## 4) Staging: Nginx + BasicAuth + deploy.php

На staging включён BasicAuth на весь сайт, но для webhook-деплоя сделано исключение:

* В vhost staging есть `location = /deploy.php`, где BasicAuth отключён.
* Запрос к `/deploy.php` обрабатывается php-fpm, чтобы выполнить деплой-скрипт.

Ожидаемая логика:

* Любые запросы к сайту → требуют BasicAuth.
* Запрос к `/deploy.php` → без BasicAuth, но с проверкой подписи GitHub (HMAC).

---

## 5) Staging deploy webhook (GitHub → сервер)

* Endpoint (прототип): `http://market-staging.176.108.244.218.nip.io/deploy.php`
* Защита: `X-Hub-Signature-256` (HMAC SHA256) по `DEPLOY_WEBHOOK_SECRET` из `.env` staging
* Лог: `storage/logs/deploy-market-staging.log`
* Событие GitHub webhook: `push`

Рекомендации:

* Никогда не логировать секреты и полный payload.
* В случае ошибок сначала смотреть `deploy-market-staging.log`, затем `storage/logs/laravel.log`.

---

## 6) Диагностика и проверки

### 6.1) Проверка окружения и конфигурации

```bash
php artisan about
php artisan config:clear
```

### 6.2) Проверка доступа к SQLite и прав

```bash
ls -la /var/www/market-staging/current/storage /var/www/market-staging/current/bootstrap/cache
ls -la /var/www/market-staging/current/database/database.sqlite
```

Ожидаемые требования:

* `storage/` и `bootstrap/cache/` должны быть writable для пользователя веб-сервера (обычно `www-data`).
* Файл `database.sqlite` должен быть writable, иначе будут ошибки «readonly database» и проблемы с кешом/сессиями.

### 6.3) Проверка состояния миграций

```bash
php artisan migrate:status
```

### 6.4) Если «после правок .env всё сломалось»

1. Выполнить `php artisan config:clear`.
2. Проверить `.env` на «склейку» строк (особенно последние строки файла).
3. Проверить Redis prefix:

```bash
php artisan tinker --execute="echo config('database.redis.options.prefix').PHP_EOL;"
```

---

## 7) HTTPS на сервере 176.108.244.218: диагностика

Цель: понять, что именно «закрыто» и где (внутри сервера или снаружи).

### 7.1) Проверить, слушает ли сервер 80/443

```bash
sudo ss -lntp | grep -E ':(80|443)[[:space:]]'
```

Интерпретация:

* Если 443 не слушается — проблема в конфигурации nginx/сервисе/сертификатах.
* Если 443 слушается — проверяем firewall и внешний доступ.

### 7.2) Проверить firewall на сервере

Если используется UFW:

```bash
sudo ufw status verbose
```

Если используется nftables:

```bash
sudo nft list ruleset | sed -n '1,200p'
```

Если используется iptables:

```bash
sudo iptables -S
sudo iptables -L -n
```

### 7.3) Проверить конфиги nginx и перезагрузку

```bash
sudo nginx -t
sudo systemctl reload nginx
sudo systemctl status nginx --no-pager
```

### 7.4) Посмотреть логи nginx при попытке зайти по HTTPS

```bash
sudo tail -n 200 /var/log/nginx/error.log
sudo tail -n 200 /var/log/nginx/access.log
```

Результат диагностики фиксируем одной записью в `docs/ops-log.md`.

---

## 8) Типовые инциденты и быстрые причины

### 8.1) Переменные `.env` «склеились»

* Симптом: странные пути/значения, например `...database.sqliteREDIS_PREFIX=...`.
* Причина: нет перевода строки в конце `.env`.

### 8.2) SQLite readonly / ошибки записи кеша

* Симптом: нельзя логиниться, кеш/сессии не пишутся, ошибки на запись.
* Причина: права на `storage/`, `bootstrap/cache/`, `database.sqlite`.

### 8.3) Webhook: «ping проходит, push не деплоит»

Симптом: GitHub сообщает доставку, но код не обновился.

Возможные причины:

* неверный `DEPLOY_WEBHOOK_SECRET` или логика проверки подписи;
* `deploy.php` фактически требует BasicAuth (нет исключения в vhost);
* `deploy.php` не исполняется php-fpm (неверный `location` или обработчик PHP);
* права на каталог проекта мешают `git pull`/`composer`/записи логов.

Порядок проверки: `deploy-market-staging.log` → `nginx error.log` → `storage/logs/laravel.log`.
