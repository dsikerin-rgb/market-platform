#!/bin/bash
cd /var/www/market/current

# Get DB credentials from .env
DB_HOST=$(grep DB_HOST .env | cut -d= -f2 | tr -d '"')
DB_DATABASE=$(grep DB_DATABASE .env | cut -d= -f2 | tr -d '"')
DB_USERNAME=$(grep DB_USERNAME .env | cut -d= -f2 | tr -d '"')
DB_PASSWORD=$(grep DB_PASSWORD .env | cut -d= -f2 | tr -d '"')

TIMESTAMP=$(date +%Y-%m-%d_%H-%M-%S)
BACKUP_DIR=/var/www/market/backups
BACKUP_FILE="$BACKUP_DIR/prod_before_contract_space_apply_${TIMESTAMP}.dump"

mkdir -p "$BACKUP_DIR"

export PGPASSWORD="$DB_PASSWORD"
pg_dump -h "$DB_HOST" -U "$DB_USERNAME" -d "$DB_DATABASE" -Fc -f "$BACKUP_FILE"
RESULT=$?
unset PGPASSWORD

if [ $RESULT -eq 0 ]; then
    echo "BACKUP_OK=$BACKUP_FILE"
    ls -lh "$BACKUP_FILE"
else
    echo "BACKUP_FAILED"
    exit 1
fi
