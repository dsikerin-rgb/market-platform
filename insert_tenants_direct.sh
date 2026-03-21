#!/bin/bash
set -euo pipefail

echo "=== STAGING: INSERT MISSING TENANTS VIA DIRECT SQL ==="
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
echo "export DB_PASSWORD=".escapeshellarg((string) config("database.connections.pgsql.password")).";";
')"

# Find tenant IDs needed by prod financial tables but missing from staging
echo "=== FINDING MISSING TENANTS ==="
MISSING_IDS=$(psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "market" -Atc "
SELECT id FROM (
    SELECT DISTINCT tc.tenant_id AS id FROM tenant_contracts tc WHERE tc.tenant_id IS NOT NULL
    UNION
    SELECT DISTINCT ta.tenant_id AS id FROM tenant_accruals ta WHERE ta.tenant_id IS NOT NULL
) needed
WHERE id NOT IN (SELECT id FROM market_staging.public.tenants)
ORDER BY id;
")

MISSING_COUNT=$(echo "$MISSING_IDS" | wc -l)
echo "Missing tenant count: $MISSING_COUNT"

if [ "$MISSING_COUNT" -gt 0 ] && [ -n "$MISSING_IDS" ]; then
    echo "=== INSERTING MISSING TENANTS (ON CONFLICT DO NOTHING) ==="
    
    # Build INSERT statement
    for id in $MISSING_IDS; do
        psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "market_staging" -c "
        INSERT INTO tenants (id, market_id, external_id, one_c_uid, inn, kpp, name, short_name, slug, display_name, phone, email, website, address, gps_lat, gps_lon, created_at, updated_at)
        SELECT id, market_id, external_id, one_c_uid, inn, kpp, name, short_name, slug, display_name, phone, email, website, address, gps_lat, gps_lon, created_at, updated_at
        FROM market.public.tenants
        WHERE id = $id
        ON CONFLICT (id) DO NOTHING;
        " 2>&1 || true
    done
    
    echo "=== TENANTS INSERTED ==="
else
    echo "=== NO MISSING TENANTS ==="
fi

echo "=== VERIFY TENANTS COUNT ==="
psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -Atc "select 'tenants_count=' || count(*) from tenants;"

unset PGPASSWORD DB_HOST DB_PORT DB_NAME DB_USER DB_PASSWORD
