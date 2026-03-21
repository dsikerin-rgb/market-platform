#!/bin/bash
set -euo pipefail

echo "=== STAGING: INSERT MISSING TENANTS ==="
cd /var/www/market-staging/current

# Get DB config from Laravel
DB_HOST=$(php -r "require 'vendor/autoload.php'; \$app = require 'bootstrap/app.php'; \$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo config('database.connections.pgsql.host');")
DB_PORT=$(php -r "require 'vendor/autoload.php'; \$app = require 'bootstrap/app.php'; \$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo config('database.connections.pgsql.port');")
DB_NAME_STAGING=$(php -r "require 'vendor/autoload.php'; \$app = require 'bootstrap/app.php'; \$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo config('database.connections.pgsql.database');")
DB_NAME_PROD="market"
DB_USER=$(php -r "require 'vendor/autoload.php'; \$app = require 'bootstrap/app.php'; \$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo config('database.connections.pgsql.username');")
DB_PASSWORD=$(php -r "require 'vendor/autoload.php'; \$app = require 'bootstrap/app.php'; \$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo config('database.connections.pgsql.password');")

export PGPASSWORD="$DB_PASSWORD"

# Get staging tenant columns (excluding system columns)
echo "=== GETTING STAGING TENANTS COLUMNS ==="
STAGING_COLS=$(psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME_STAGING" -tAc "
SELECT string_agg(column_name, ',') FROM (
    SELECT column_name FROM information_schema.columns 
    WHERE table_name = 'tenants' AND column_name NOT IN ('id', 'created_at', 'updated_at')
    ORDER BY ordinal_position
) t;
")
echo "Staging columns: $STAGING_COLS"

# Get staging tenant IDs
echo "=== GETTING STAGING TENANT IDS ==="
STAGING_IDS=$(psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME_STAGING" -tAc "SELECT string_agg(id::text, ',') FROM (SELECT id FROM tenants ORDER BY id) t;")
STAGING_COUNT=$(echo "$STAGING_IDS" | tr ',' '\n' | wc -l)
echo "Staging has $STAGING_COUNT tenants"

# Get prod tenant IDs needed by financial tables
echo "=== GETTING PROD TENANT IDS NEEDED ==="
PROD_IDS=$(psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME_PROD" -tAc "
SELECT string_agg(id::text, ',') FROM (
    SELECT DISTINCT tc.tenant_id AS id FROM tenant_contracts tc WHERE tc.tenant_id IS NOT NULL
    UNION
    SELECT DISTINCT ta.tenant_id AS id FROM tenant_accruals ta WHERE ta.tenant_id IS NOT NULL
    ORDER BY id
) t;
")
PROD_COUNT=$(echo "$PROD_IDS" | tr ',' '\n' | wc -l)
echo "Prod needs $PROD_COUNT tenants"

# Find missing IDs using PHP
echo "=== FINDING MISSING TENANTS ==="
MISSING_IDS=$(php -r "
\$staging = array_flip(explode(',', '$STAGING_IDS'));
\$prod = explode(',', '$PROD_IDS');
\$missing = array_filter(\$prod, function(\$id) use (\$staging) { return !isset(\$staging[\$id]); });
echo implode(',', \$missing);
")
MISSING_COUNT=$(echo "$MISSING_IDS" | tr ',' '\n' | grep -c . || echo 0)
echo "Missing tenant count: $MISSING_COUNT"

if [ "$MISSING_COUNT" -gt 0 ] && [ -n "$MISSING_IDS" ]; then
    echo "=== INSERTING MISSING TENANTS ==="
    
    # Build column lists for INSERT
    ALL_COLS="id,$STAGING_COLS,created_at,updated_at"
    
    # Insert each missing tenant
    for id in $(echo "$MISSING_IDS" | tr ',' ' '); do
        echo "Inserting tenant $id..."
        psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME_STAGING" -c "
        INSERT INTO tenants ($ALL_COLS)
        SELECT $ALL_COLS
        FROM ${DB_NAME_PROD}.public.tenants
        WHERE id = $id
        ON CONFLICT (id) DO NOTHING;
        " 2>&1 || true
    done
    
    echo "=== TENANTS INSERTED ==="
else
    echo "=== NO MISSING TENANTS ==="
fi

echo "=== VERIFY TENANTS COUNT ==="
psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME_STAGING" -Atc "select 'tenants_count=' || count(*) from tenants;"

unset PGPASSWORD
