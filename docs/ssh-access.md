# SSH-доступ к серверам

Документ описывает подключение к staging и production серверам проекта.

---

## 🔑 SSH-конфигурация

### Расположение ключей

```
C:\Users\New\.ssh\
├── config              # Конфигурация SSH
├── id_ed25519         # Приватный ключ
├── id_ed25519.pub     # Публичный ключ
└── known_hosts        # Известные хосты
```

### Файл `~/.ssh/config`

```ssh
Host my_projects
    HostName 176.108.244.218
    User otelpro
    Port 22
    IdentityFile C:/Users/New/.ssh/id_ed25519
    ForwardAgent yes
```

---

## 🖥️ Серверы

| Окружение | URL | Путь | PHP-FPM config |
|-----------|-----|------|----------------|
| **Staging** | `http://market-staging.176.108.244.218.nip.io` | `/var/www/market-staging/current` | `/etc/php/8.3/fpm/php.ini` |
| **Production** | `http://market.176.108.244.218.nip.io` | `/var/www/market/current` | `/etc/php/8.3/fpm/php.ini` |

---

## 📋 Базовые команды

### Подключение к серверу

```bash
# Полный вариант
ssh -i C:\Users\New\.ssh\id_ed25519 otelpro@176.108.244.218

# Через SSH config (короче)
ssh my_projects
```

### Проверка подключения

```bash
ssh my_projects "php -r 'echo php_ini_loaded_file();'"
# Ожидаемый вывод: /etc/php/8.3/cli/php.ini
```

---

## 🔧 Настройка upload_tmp_dir

**Проблема:** При загрузке файлов через Livewire/Filament возникает ошибка `Path cannot be empty`, если не настроена директива `upload_tmp_dir` в PHP.

### Команды для настройки

```bash
# 1. Добавить настройку в php.ini для FPM
ssh my_projects \
  "echo 'upload_tmp_dir = /tmp' | sudo tee -a /etc/php/8.3/fpm/php.ini"

# 2. Исправить старую закомментированную строку (если есть)
ssh my_projects \
  "sudo sed -i 's/^;upload_tmp_dir\s*=/;upload_tmp_dir_disabled =/g' /etc/php/8.3/fpm/php.ini"

# 3. Перезапустить PHP-FPM
ssh my_projects \
  "sudo systemctl restart php8.3-fpm"

# 4. Проверить настройку
ssh my_projects \
  "sudo cat /etc/php/8.3/fpm/php.ini | grep -E '^upload_tmp_dir'"
# Ожидаемый вывод: upload_tmp_dir = /tmp
```

### Проверка через PHP

```bash
# Создать тестовый файл
ssh my_projects \
  "echo '<?php echo \"upload_tmp_dir: \" . ini_get(\"upload_tmp_dir\") . PHP_EOL; ?>' | sudo tee /var/www/market-staging/current/public/test-upload.php"

# Проверить через браузер (для staging потребуется BasicAuth)
curl -sL http://market-staging.176.108.244.218.nip.io/test-upload.php

# Удалить тестовый файл
ssh my_projects \
  "sudo rm /var/www/market-staging/current/public/test-upload.php"
```

---

## 🧹 Очистка кеша Laravel

### Staging

```bash
ssh my_projects \
  "cd /var/www/market-staging/current && sudo -u www-data php artisan config:clear && php artisan cache:clear && php artisan view:clear"
```

### Production

```bash
ssh my_projects \
  "cd /var/www/market/current && sudo -u www-data php artisan config:clear && php artisan cache:clear && php artisan view:clear"
```

---

## 📦 Деплой

### Staging (автоматически через webhook)

Webhook GitHub автоматически деплоит изменения из ветки `main`:

```
POST http://market-staging.176.108.244.218.nip.io/deploy.php
```

Лог деплоя: `storage/logs/deploy-market-staging.log`

### Production (вручную)

```bash
ssh my_projects
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

## 🔍 Диагностика

### Проверка версии кода

```bash
ssh my_projects "cd /var/www/market-staging/current && git log -1 --oneline"
ssh my_projects "cd /var/www/market/current && git log -1 --oneline"
```

### Проверка статуса Horizon

```bash
# Staging
ssh my_projects "sudo systemctl is-active market-staging-horizon.service"

# Production
ssh my_projects "sudo systemctl is-active market-horizon.service"
```

### Проверка прав доступа

```bash
ssh my_projects "ls -la /var/www/market-staging/current/storage"
ssh my_projects "ls -la /var/www/market-staging/current/database/database.sqlite"
```

### Логи

```bash
# Staging
ssh my_projects "tail -f /var/www/market-staging/current/storage/logs/laravel.log"
ssh my_projects "tail -f /var/www/market-staging/current/storage/logs/deploy-market-staging.log"

# Production
ssh my_projects "tail -f /var/www/market/current/storage/logs/laravel.log"
```

---

## 🔐 Безопасность

### ⚠️ Важно

1. **Не коммитьте SSH-ключи в репозиторий** — добавьте `.ssh/id_*` в `.gitignore`
2. **Не храните пароли в открытом виде** — используйте SSH-ключи
3. **`ForwardAgent yes`** используйте только для доверенных хостов

### Рекомендуемая структура `.ssh/config`

```ssh
# Проект Market Platform
Host market-prod
    HostName 176.108.244.218
    User otelpro
    Port 22
    IdentityFile ~/.ssh/id_ed25519
    ForwardAgent no

Host market-staging
    HostName 176.108.244.218
    User otelpro
    Port 22
    IdentityFile ~/.ssh/id_ed25519
    ForwardAgent yes
```

---

## 📚 Связанные документы

- [`ops.md`](ops.md) — эксплуатация, окружения, Redis, Horizon
- [`ops-log.md`](ops-log.md) — журнал операций
- [`notifications-runbook.md`](notifications-runbook.md) — диагностика уведомлений
