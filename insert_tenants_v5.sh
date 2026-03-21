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
psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME_STAGING" -Atc "SELECT id FROM tenants ORDER BY id;" > /tmp/staging_tenants.txt
STAGING_COUNT=$(wc -l < /tmp/staging_tenants.txt)
echo "Staging has $STAGING_COUNT tenants"

# Get prod tenant IDs needed by financial tables
echo "=== GETTING PROD TENANT IDS NEEDED ==="
psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME_PROD" -Atc "
SELECT DISTINCT tc.tenant_id AS id FROM tenant_contracts tc WHERE tc.tenant_id IS NOT NULL
UNION
SELECT DISTINCT ta.tenant_id AS id FROM tenant_accruals ta WHERE ta.tenant_id IS NOT NULL
ORDER BY id;
" > /tmp/prod_needed_tenants.txt
PROD_COUNT=$(wc -l < /tmp/prod_needed_tenants.txt)
echo "Prod needs $PROD_COUNT tenants"

# Find missing IDs (in prod but not in staging)
echo "=== FINDING MISSING TENANTS ==="
comm -23 /tmp/prod_needed_tenants.txt /tmp/staging_tenants.txt > /tmp/missing_tenants.txt
MISSING_COUNT=$(wc -l < /tmp/missing_tenants.txt)
echo "Missing tenant count: $MISSING_COUNT"

if [ "$MISSING_COUNT" -gt 0 ]; then
    echo "Missing IDs: $(cat /tmp/missing_tenants.txt | tr '\n' ',')"
    
    echo "=== INSERTING MISSING TENANTS (ON CONFLICT DO NOTHING) ==="
    
    # Insert each missing tenant
    while read id; do
        echo "Inserting tenant $id..."
        psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME_STAGING" -c "
        INSERT INTO tenants (id, market_id, external_id, one_c_uid, inn, kpp, name, short_name, slug, display_name, phone, email, website, address, gps_lat, gps_lon, created_at, updated_at)
        SELECT id, market_id, external_id, one_c_uid, inn, kpp, name, short_name, slug, display_name, phone, email, website, address, gps_lat, gps_lon, created_at, updated_at
        FROM ${DB_NAME_PROD}.public.tenants
        WHERE id = $id
        ON CONFLICT (id) DO NOTHING;
        " 2>&1 || true
    done < /tmp/missing_tenants.txt
    
    echo "=== TENANTS INSERTED ==="
else
    echo "=== NO MISSING TENANTS ==="
fi

echo "=== VERIFY TENANTS COUNT ==="
psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME_STAGING" -Atc "select 'tenants_count=' || count(*) from tenants;"

# Cleanup
rm -f /tmp/staging_tenants.txt /tmp/prod_needed_tenants.txt /tmp/missing_tenants.txt

unset PGPASSWORD
