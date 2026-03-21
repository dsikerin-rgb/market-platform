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

# Get staging tenant IDs
echo "=== GETTING STAGING TENANT IDS ==="
STAGING_IDS=$(psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME_STAGING" -Atc "SELECT string_agg(id::text, ',' ORDER BY id) FROM tenants;")
echo "Staging has $(echo "$STAGING_IDS" | tr ',' '\n' | wc -l) tenants"

# Get prod tenant IDs needed by financial tables
echo "=== GETTING PROD TENANT IDS NEEDED ==="
PROD_NEEDED=$(psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME_PROD" -Atc "SELECT string_agg(DISTINCT id::text, ',' ORDER BY id) FROM (SELECT tc.tenant_id AS id FROM tenant_contracts tc WHERE tc.tenant_id IS NOT NULL UNION SELECT ta.tenant_id AS id FROM tenant_accruals ta WHERE ta.tenant_id IS NOT NULL) needed;")
echo "Prod needs $(echo "$PROD_NEEDED" | tr ',' '\n' | wc -l) tenants"

# Find missing IDs (in prod but not in staging)
echo "=== FINDING MISSING TENANTS ==="
MISSING_IDS=$(comm -23 <(echo "$PROD_NEEDED" | tr ',' '\n' | sort -n) <(echo "$STAGING_IDS" | tr ',' '\n' | sort -n) | tr '\n' ',' | sed 's/,$//')
MISSING_COUNT=$(echo "$MISSING_IDS" | tr ',' '\n' | grep -c . || echo 0)
echo "Missing tenant count: $MISSING_COUNT"
if [ -n "$MISSING_IDS" ]; then
    echo "Missing IDs: $MISSING_IDS"
fi

if [ "$MISSING_COUNT" -gt 0 ] && [ -n "$MISSING_IDS" ]; then
    echo "=== INSERTING MISSING TENANTS (ON CONFLICT DO NOTHING) ==="
    
    # Insert each missing tenant
    for id in $(echo "$MISSING_IDS" | tr ',' ' '); do
        echo "Inserting tenant $id..."
        psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME_STAGING" -c "
        INSERT INTO tenants (id, market_id, external_id, one_c_uid, inn, kpp, name, short_name, slug, display_name, phone, email, website, address, gps_lat, gps_lon, created_at, updated_at)
        SELECT id, market_id, external_id, one_c_uid, inn, kpp, name, short_name, slug, display_name, phone, email, website, address, gps_lat, gps_lon, created_at, updated_at
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
