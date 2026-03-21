#!/bin/bash
set -euo pipefail

echo "=== STAGING: INSERT MISSING TENANTS VIA SQL ==="
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

echo "=== INSERT MISSING TENANTS (ON CONFLICT DO NOTHING) ==="
psql -v ON_ERROR_STOP=1 -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -c "
INSERT INTO tenants (id, market_id, external_id, one_c_uid, inn, kpp, name, short_name, slug, display_name, phone, email, website, address, gps_lat, gps_lon, created_at, updated_at)
SELECT id, market_id, external_id, one_c_uid, inn, kpp, name, short_name, slug, display_name, phone, email, website, address, gps_lat, gps_lon, created_at, updated_at
FROM dblink('host=$DB_HOST port=$DB_PORT dbname=market user=$DB_USER password=$DB_PASSWORD',
  'SELECT id, market_id, external_id, one_c_uid, inn, kpp, name, short_name, slug, display_name, phone, email, website, address, gps_lat, gps_lon, created_at, updated_at
   FROM tenants
   WHERE id NOT IN (SELECT id FROM tenants)')
AS t(id bigint, market_id bigint, external_id character varying, one_c_uid character varying, inn character varying, kpp character varying, name character varying, short_name character varying, slug character varying, display_name character varying, phone character varying, email character varying, website character varying, address character varying, gps_lat numeric, gps_lon numeric, created_at timestamp without time zone, updated_at timestamp without time zone)
ON CONFLICT (id) DO NOTHING;
" 2>&1 || echo "DBLINK approach failed, trying simpler approach"

# Simpler approach: export missing tenants as SQL INSERT
echo "=== EXPORT MISSING TENANTS AS INSERT SQL ==="
MISSING_IDS=$(psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "market_staging" -Atc "
SELECT string_agg(id::text, ',') FROM (
    SELECT id FROM (
        SELECT DISTINCT tc.tenant_id AS id FROM tenant_contracts tc WHERE tc.tenant_id IS NOT NULL
        UNION
        SELECT DISTINCT ta.tenant_id AS id FROM tenant_accruals ta WHERE ta.tenant_id IS NOT NULL
    ) all_needed
    EXCEPT
    SELECT id FROM tenants
) missing;
")

echo "Missing tenant IDs from prod financial tables: $MISSING_IDS"

if [ -n "$MISSING_IDS" ]; then
    # Export missing tenants from prod
    psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "market" -Atc "
    COPY (
        SELECT id, market_id, external_id, one_c_uid, inn, kpp, name, short_name, slug, display_name, phone, email, website, address, gps_lat, gps_lon, created_at, updated_at
        FROM tenants
        WHERE id IN ($MISSING_IDS)
    ) TO STDOUT WITH CSV HEADER
    " > /tmp/missing_tenants.csv
    
    echo "=== IMPORTING MISSING TENANTS TO STAGING ==="
    psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "market_staging" -c "
    CREATE TEMP TABLE missing_tenants_tmp (
        id bigint, market_id bigint, external_id character varying, one_c_uid character varying,
        inn character varying, kpp character varying, name character varying, short_name character varying,
        slug character varying, display_name character varying, phone character varying, email character varying,
        website character varying, address character varying, gps_lat numeric, gps_lon numeric,
        created_at timestamp without time zone, updated_at timestamp without time zone
    );
    "
    
    psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "market_staging" -c "\COPY missing_tenants_tmp FROM '/tmp/missing_tenants.csv' WITH CSV HEADER"
    
    psql -v ON_ERROR_STOP=1 -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "market_staging" -c "
    INSERT INTO tenants (id, market_id, external_id, one_c_uid, inn, kpp, name, short_name, slug, display_name, phone, email, website, address, gps_lat, gps_lon, created_at, updated_at)
    SELECT id, market_id, external_id, one_c_uid, inn, kpp, name, short_name, slug, display_name, phone, email, website, address, gps_lat, gps_lon, created_at, updated_at
    FROM missing_tenants_tmp
    ON CONFLICT (id) DO NOTHING;
    
    DROP TABLE missing_tenants_tmp;
    "
    
    echo "=== TENANTS INSERTED ==="
else
    echo "=== NO MISSING TENANTS NEEDED ==="
fi

echo "=== VERIFY TENANTS COUNT ==="
psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -Atc "select 'tenants_count=' || count(*) from tenants;"

unset PGPASSWORD DB_HOST DB_PORT DB_NAME DB_USER
