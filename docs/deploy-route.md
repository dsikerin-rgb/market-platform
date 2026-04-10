# 🚀 Маршрут деплоя Market Platform

Полная инструкция по развёртыванию и управлению серверами staging и production.

---

## 🔑 SSH-доступ

### Конфигурация

Файл: `C:\Users\New\.ssh\config`

```ssh
Host my_projects
    HostName 176.108.244.218
    User otelpro
    Port 22
    IdentityFile C:/Users/New/.ssh/id_ed25519
    ForwardAgent yes
```

### Базовое подключение

```bash
# Полный формат
ssh -i C:\Users\New\.ssh\id_ed25519 -o StrictHostKeyChecking=no otelpro@176.108.244.218 "команда"

# Через SSH alias (короче)
ssh my_projects "команда"
```

**Параметры:**
- `-i C:\Users\New\.ssh\id_ed25519` — путь к приватному ключу
- `-o StrictHostKeyChecking=no` — не спрашивать про fingerprint
- `otelpro@176.108.244.218` — пользователь и сервер
- `"команда"` — выполняется на сервере

---

## 📦 Пути на сервере

| Окружение | Путь | URL |
|-----------|------|-----|
| **Staging** | `/var/www/market-staging/current` | `https://market-staging.176.108.244.218.nip.io` |
| **Production** | `/var/www/market/current` | `https://market.176.108.244.218.nip.io` |

---

## 🔄 Полный рабочий процесс

```
┌─────────────────────────────────────────────────────────────────┐
│ 1. Local разработка                                            │
│    git checkout -b fix/feature                                 │
│    # правки кода                                               │
│    git add . && git commit -m "fix: ..."                       │
│    git push origin fix/feature                                 │
└─────────────────────────────────────────────────────────────────┘
                           ↓
┌─────────────────────────────────────────────────────────────────┐
│ 2. PR в main                                                   │
│    GitHub → Pull requests → New PR                             │
│    base: main, compare: fix/feature                            │
└─────────────────────────────────────────────────────────────────┘
                           ↓
┌─────────────────────────────────────────────────────────────────┐
│ 3. Staging (авто)                                              │
│    После merge в main → webhook → автодеплой                   │
│    Проверка: https://market-staging.../admin                   │
└─────────────────────────────────────────────────────────────────┘
                           ↓
┌─────────────────────────────────────────────────────────────────┐
│ 4. Production (вручную)                                        │
│    SSH → checkout ветки → composer install → clear cache       │
│    Проверка: https://market.../admin                           │
└─────────────────────────────────────────────────────────────────┘
```

---

## 📥 Варианты деплоя

### Вариант A: Через Git (предпочтительно)

```bash
# 1. Fetch ветки
git fetch origin fix/market-map-debt-display

# 2. Checkout ветки
git checkout -b fix/market-map-debt-display origin/fix/market-map-debt-display

# 3. Установка зависимостей
composer install --no-dev --optimize-autoloader

# 4. Очистка кеша
php artisan optimize:clear

# 5. Миграции (только если PR действительно содержит миграцию)
php artisan migrate --force

# 6. Перезапуск Horizon
php artisan horizon:terminate || true
```

### Вариант B: Через SCP (для быстрых правок)

```bash
# Копирование файла
scp -i C:\Users\New\.ssh\id_ed25519 -o StrictHostKeyChecking=no локальный_файл otelpro@176.108.244.218:/путь/на/сервере

# Пример
scp -i C:\Users\New\.ssh\id_ed25519 app/Filament/Resources/TenantResource.php otelpro@176.108.244.218:/var/www/market/current/app/Filament/Resources/TenantResource.php
```

---

## 🚀 Деплой на окружения

### Staging (автоматически)

Staging настроен на автодеплой от `main` через webhook:

```
https://market-staging.176.108.244.218.nip.io/deploy.php
```

**Схема работы:**
```
GitHub → webhook → /usr/local/bin/deploy_market_staging.sh → deploy
```

**Проверка после деплоя:**
```bash
ssh my_projects "cd /var/www/market-staging/current && git log -1 --oneline"
ssh my_projects "cd /var/www/market-staging/current && php artisan horizon:status"
```

### Production (вручную)

#### Деплой main

```bash
ssh my_projects "cd /var/www/market/current && git fetch origin main && git switch main && git pull --ff-only origin main && sudo -u www-data php artisan optimize:clear && git rev-parse --short HEAD"
```

#### Деплой конкретной ветки

```bash
ssh my_projects "cd /var/www/market/current && git fetch origin <branch> && git switch <branch> && git pull --ff-only origin <branch> && sudo -u www-data php artisan optimize:clear"
```

#### Полный деплой с миграциями и Horizon

```bash
ssh my_projects "cd /var/www/market/current && php artisan tinker --execute=\"echo \\\"DB_CONNECTION=\\\".config(\\\"database.default\\\").PHP_EOL; echo \\\"DB_HOST=\\\".config(\\\"database.connections.\\\".config(\\\"database.default\\\").\\\".host\\\").PHP_EOL; echo \\\"DB_PORT=\\\".config(\\\"database.connections.\\\".config(\\\"database.default\\\").\\\".port\\\").PHP_EOL; echo \\\"DB_DATABASE=\\\".config(\\\"database.connections.\\\".config(\\\"database.default\\\").\\\".database\\\").PHP_EOL; echo \\\"DB_USERNAME=\\\".config(\\\"database.connections.\\\".config(\\\"database.default\\\").\\\".username\\\").PHP_EOL;\" && mkdir -p /var/www/market/backups && pg_dump -h <DB_HOST> -p <DB_PORT> -U <DB_USERNAME> -d <DB_DATABASE> -Fc -f /var/www/market/backups/prod_before_deploy_TIMESTAMP.dump && git fetch origin main && git switch main && git pull --ff-only origin main && composer install --no-dev --optimize-autoloader && php artisan optimize:clear && php artisan filament:upgrade && php artisan horizon:terminate || true && git rev-parse --short HEAD && git log --oneline -1"
```

Если PR действительно содержит миграцию, выполните отдельным шагом:

```bash
ssh my_projects "cd /var/www/market/current && php artisan migrate --force"
```

---

## 🛠️ Мои команды (примеры)

### Проверка статуса на сервере

```bash
ssh my_projects "cd /var/www/market/current && git status"
```

### Деплой на prod

```bash
ssh my_projects "cd /var/www/market/current && git fetch && git checkout main && git pull && composer install --no-dev -o && php artisan optimize:clear"
```

### Копирование файла

```bash
scp -i C:\Users\New\.ssh\id_ed25519 app/Models/Tenant.php otelpro@176.108.244.218:/var/www/market/current/app/Models/Tenant.php
```

### Проверка версии кода

```bash
ssh my_projects "cd /var/www/market/current && git log -1 --oneline"
```

### Проверка Horizon

```bash
# Production
ssh my_projects "sudo systemctl is-active market-horizon.service"

# Staging
ssh my_projects "sudo systemctl is-active market-staging-horizon.service"
```

---

## 🔧 Создание PR в main

### Через GitHub CLI (недоступно)

```bash
gh pr create --base main --head fix/market-map-debt-display --title "..." --body "..."
```

### Через веб-интерфейс (ручной способ)

1. Зайти на https://github.com/dsikerin-rgb/market-platform
2. Нажать **"Pull requests"** → **"New pull request"**
3. Выбрать:
   - **base:** `main`
   - **compare:** `fix/market-map-debt-display`
4. Заполнить **title** и **description**
5. Нажать **"Create pull request"**
6. **Открыть PR**
7. Нажать **"Merge pull request"**
8. **Confirm merge**

---

## ⚠️ Важные замечания

### Если на сервере грязное дерево

```bash
# Проверка
ssh my_projects "cd /var/www/market/current && git status"

# Если грязное - сохранить в backup-ветку
ssh my_projects "cd /var/www/market/current && git checkout -b backup/local-YYYY-MM-DD"

# Или вернуть main через fast-forward
ssh my_projects "cd /var/www/market/current && git switch main && git pull --ff-only origin main"
```

### Точечное копирование файлов

Если `git pull` не проходит, можно использовать `scp`:

```bash
# Копирование одного файла
scp -i C:\Users\New\.ssh\id_ed25519 app/Models/Tenant.php otelpro@176.108.244.218:/var/www/market/current/app/Models/Tenant.php

# Копирование директории
scp -r -i C:\Users\New\.ssh\id_ed25519 app/Models otelpro@176.108.244.218:/var/www/market/current/app/
```

---

## 📊 Диагностика

### Проверка логов

```bash
# Laravel log
ssh my_projects "tail -f /var/www/market/current/storage/logs/laravel.log"

# Deploy log (staging)
ssh my_projects "tail -f /var/www/market-staging/current/storage/logs/deploy-market-staging.log"

# Nginx error log
ssh my_projects "sudo tail -f /var/log/nginx/error.log"
```

### Проверка прав доступа

```bash
ssh my_projects "ls -la /var/www/market/current/storage"
ssh my_projects "cd /var/www/market/current && php artisan tinker --execute=\"echo \\\"DB_CONNECTION=\\\".config(\\\"database.default\\\").PHP_EOL; echo \\\"DB_HOST=\\\".config(\\\"database.connections.\\\".config(\\\"database.default\\\").\\\".host\\\").PHP_EOL; echo \\\"DB_PORT=\\\".config(\\\"database.connections.\\\".config(\\\"database.default\\\").\\\".port\\\").PHP_EOL; echo \\\"DB_DATABASE=\\\".config(\\\"database.connections.\\\".config(\\\"database.default\\\").\\\".database\\\").PHP_EOL; echo \\\"DB_USERNAME=\\\".config(\\\"database.connections.\\\".config(\\\"database.default\\\").\\\".username\\\").PHP_EOL;\""
```

---

## 🔗 Связанные документы

- [`docs/ssh-access.md`](ssh-access.md) — SSH-доступ и настройка `upload_tmp_dir`
- [`docs/ops.md`](ops.md) — эксплуатация, окружения, Redis, Horizon
- [`docs/ops-log.md`](ops-log.md) — журнал операций
- [`1C_HANDOFF.md`](../1C_HANDOFF.md) — передача по интеграции с 1C
