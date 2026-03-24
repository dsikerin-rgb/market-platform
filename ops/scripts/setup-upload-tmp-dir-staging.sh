#!/bin/bash
# Скрипт настройки upload_tmp_dir для staging
# Запускается на сервере staging

set -e

echo "=== Настройка upload_tmp_dir для staging ==="

# Найти php.ini
PHP_INI=$(php -r "echo php_ini_loaded_file();")
echo "Найден php.ini: $PHP_INI"

# Проверить, есть ли уже настройка
if grep -q "^upload_tmp_dir" "$PHP_INI"; then
    echo "upload_tmp_dir уже настроен"
    grep "^upload_tmp_dir" "$PHP_INI"
else
    echo "Настраиваем upload_tmp_dir = /tmp"
    # Закомментировать старую строку если есть и добавить новую
    sed -i 's/^;*\s*upload_tmp_dir\s*=/;upload_tmp_dir =/g' "$PHP_INI"
    echo "upload_tmp_dir = /tmp" >> "$PHP_INI"
    echo "✓ upload_tmp_dir настроен"
fi

# Проверить результат
echo ""
echo "Проверка:"
php -i | grep upload_tmp_dir

# Перезапустить PHP-FPM
echo ""
echo "Перезапуск PHP-FPM..."
sudo systemctl restart php8.3-fpm || sudo systemctl restart php-fpm || echo "Возможно, требуется ручной перезапуск PHP-FPM"

echo ""
echo "=== Готово ==="
echo "Теперь проверьте загрузку файлов в админке"
