#!/bin/bash
set -euo pipefail

echo "=== PROD: CREATE MISSING TENANTS DUMP ==="
cd /var/www/market/current

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

# Get staging tenant IDs
STAGING_TENANTS=$(psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "market_staging" -Atc "select id from tenants order by id" | tr '\n' ',' | sed 's/,$//')

echo "=== STAGING TENANT IDS ==="
echo "staging_tenants=$STAGING_TENANTS"

# Create dump of tenants NOT in staging
TENANTS_DUMP="/var/www/market/backups/prod_missing_tenants_custom_$(date +%F_%H-%M-%S).dump"

echo "=== CREATING MISSING TENANTS DUMP ==="
psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -Atc "
select id from tenants
where id not in ($STAGING_TENANTS)
order by id;
" > /tmp/missing_tenant_ids.txt

echo "=== MISSING TENANT COUNT ==="
wc -l /tmp/missing_tenant_ids.txt

if [ -s /tmp/missing_tenant_ids.txt ]; then
    # Create dump of only missing tenants
    pg_dump \
      -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" \
      -Fc \
      --data-only \
      --no-owner \
      --no-privileges \
      --table=public.tenants \
      -f "$TENANTS_DUMP"
    
    echo "=== MISSING TENANTS DUMP FILE ==="
    ls -lh "$TENANTS_DUMP"
    sha256sum "$TENANTS_DUMP"
else
    echo "=== NO MISSING TENANTS ==="
    echo "All prod tenants already exist on staging"
fi

unset PGPASSWORD DB_HOST DB_PORT DB_NAME DB_USER
