#!/bin/bash
set -euo pipefail

echo "=== STAGING: INSERT MISSING TENANTS ==="
cd /var/www/market-staging/current

eval "$(php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
echo "export DB_HOST=".escapeshellarg(config("database.connections.pgsql.host")).";";
echo "export DB_PORT=".escapeshellarg((string) config("database.connections.pgsql.port")).";";
echo "export DB_NAME=".escapeshellarg(config("database.connections.pgsql.database")).";";
echo "export DB_USER=".escapeshellarg(config("database.connections.pgsql.username")).";";
echo "export PGPASSWORD=".escapeshellarg((string) config("database.connections.pgsql.password")).";";
')"

# Get tenant IDs that exist in prod financial tables but not in staging
echo "=== FINDING MISSING TENANTS ==="
MISSING_IDS=$(psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "market" -Atc "
SELECT string_agg(id::text, ',') FROM (
    SELECT DISTINCT tc.tenant_id AS id FROM tenant_contracts tc WHERE tc.tenant_id IS NOT NULL
    UNION
    SELECT DISTINCT ta.tenant_id AS id FROM tenant_accruals ta WHERE ta.tenant_id IS NOT NULL
) needed
WHERE id NOT IN (SELECT id FROM market_staging.public.tenants);
")

echo "Missing tenant IDs: $MISSING_IDS"

if [ -n "$MISSING_IDS" ]; then
    echo "=== EXPORTING MISSING TENANTS FROM PROD ==="
    PGPASSWORD="$DB_PASSWORD" psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "market" -c "
    COPY (
        SELECT id, market_id, external_id, one_c_uid, inn, kpp, name, short_name, slug, display_name, phone, email, website, address, gps_lat, gps_lon, created_at, updated_at
        FROM tenants
        WHERE id IN ($MISSING_IDS)
    ) TO '/tmp/missing_tenants.csv' WITH CSV HEADER;
    "
    
    echo "=== IMPORTING TO STAGING (ON CONFLICT DO NOTHING) ==="
    PGPASSWORD="$DB_PASSWORD" psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "market_staging" -c "
    CREATE TEMP TABLE missing_tenants_tmp (
        id bigint, market_id bigint, external_id varchar, one_c_uid varchar,
        inn varchar, kpp varchar, name varchar, short_name varchar,
        slug varchar, display_name varchar, phone varchar, email varchar,
        website varchar, address varchar, gps_lat numeric, gps_lon numeric,
        created_at timestamp, updated_at timestamp
    );
    \COPY missing_tenants_tmp FROM '/tmp/missing_tenants.csv' WITH CSV HEADER;
    
    INSERT INTO tenants (id, market_id, external_id, one_c_uid, inn, kpp, name, short_name, slug, display_name, phone, email, website, address, gps_lat, gps_lon, created_at, updated_at)
    SELECT id, market_id, external_id, one_c_uid, inn, kpp, name, short_name, slug, display_name, phone, email, website, address, gps_lat, gps_lon, created_at, updated_at
    FROM missing_tenants_tmp
    ON CONFLICT (id) DO NOTHING;
    
    DROP TABLE missing_tenants_tmp;
    "
    
    echo "=== TENANTS INSERTED ==="
else
    echo "=== NO MISSING TENANTS ==="
fi

echo "=== VERIFY TENANTS COUNT ==="
psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -Atc "select 'tenants_count=' || count(*) from tenants;"

unset PGPASSWORD DB_HOST DB_PORT DB_NAME DB_USER
