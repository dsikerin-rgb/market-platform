#!/bin/bash
set -euo pipefail

echo "=== STAGING: INSERT MISSING TENANTS VIA COPY ==="
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
psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME_STAGING" -tAc "SELECT id FROM tenants ORDER BY id;" > /tmp/staging_tenants.txt
STAGING_COUNT=$(wc -l < /tmp/staging_tenants.txt)
echo "Staging has $STAGING_COUNT tenants"

# Get prod tenant IDs needed by financial tables
echo "=== GETTING PROD TENANT IDS NEEDED ==="
psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME_PROD" -tAc "
SELECT id FROM (
    SELECT DISTINCT tc.tenant_id AS id FROM tenant_contracts tc WHERE tc.tenant_id IS NOT NULL
    UNION
    SELECT DISTINCT ta.tenant_id AS id FROM tenant_accruals ta WHERE ta.tenant_id IS NOT NULL
) t ORDER BY id;
" > /tmp/prod_needed_tenants.txt
PROD_COUNT=$(wc -l < /tmp/prod_needed_tenants.txt)
echo "Prod needs $PROD_COUNT tenants"

# Find missing IDs
echo "=== FINDING MISSING TENANTS ==="
sort -n /tmp/staging_tenants.txt -o /tmp/staging_tenants.txt
sort -n /tmp/prod_needed_tenants.txt -o /tmp/prod_needed_tenants.txt
comm -23 /tmp/prod_needed_tenants.txt /tmp/staging_tenants.txt > /tmp/missing_tenants.txt
MISSING_COUNT=$(wc -l < /tmp/missing_tenants.txt)
echo "Missing tenant count: $MISSING_COUNT"

if [ "$MISSING_COUNT" -gt 0 ]; then
    echo "=== EXPORTING MISSING TENANTS FROM PROD ==="
    # Build WHERE clause for missing IDs
    MISSING_WHERE=$(cat /tmp/missing_tenants.txt | tr '\n' ',' | sed 's/,$//')
    
    # Export from prod to CSV
    psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME_PROD" -c "
    COPY (
        SELECT id, market_id, name, short_name, type, inn, ogrn, phone, email, contact_person, status, is_active, notes, requisites, debt_status, debt_status_note, debt_status_updated_at, slug, external_id, one_c_uid, kpp, one_c_data, created_at, updated_at
        FROM tenants
        WHERE id IN ($MISSING_WHERE)
    ) TO '/tmp/missing_tenants_export.csv' WITH CSV HEADER;
    "
    
    echo "=== IMPORTING TO STAGING (ON CONFLICT DO NOTHING) ==="
    # Import to staging
    psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME_STAGING" -c "
    CREATE TEMP TABLE missing_tenants_tmp (
        id bigint, market_id bigint, name varchar, short_name varchar, type varchar, inn varchar, ogrn varchar,
        phone varchar, email varchar, contact_person varchar, status varchar, is_active boolean,
        notes text, requisites text, debt_status varchar, debt_status_note text, debt_status_updated_at timestamp,
        slug varchar, external_id varchar, one_c_uid varchar, kpp varchar, one_c_data jsonb,
        created_at timestamp, updated_at timestamp
    );
    \COPY missing_tenants_tmp FROM '/tmp/missing_tenants_export.csv' WITH CSV HEADER;
    
    INSERT INTO tenants (id, market_id, name, short_name, type, inn, ogrn, phone, email, contact_person, status, is_active, notes, requisites, debt_status, debt_status_note, debt_status_updated_at, slug, external_id, one_c_uid, kpp, one_c_data, created_at, updated_at)
    SELECT id, market_id, name, short_name, type, inn, ogrn, phone, email, contact_person, status, is_active, notes, requisites, debt_status, debt_status_note, debt_status_updated_at, slug, external_id, one_c_uid, kpp, one_c_data, created_at, updated_at
    FROM missing_tenants_tmp
    ON CONFLICT (id) DO NOTHING;
    
    DROP TABLE missing_tenants_tmp;
    "
    
    echo "=== TENANTS INSERTED ==="
else
    echo "=== NO MISSING TENANTS ==="
fi

echo "=== VERIFY TENANTS COUNT ==="
psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME_STAGING" -Atc "select 'tenants_count=' || count(*) from tenants;"

# Cleanup
rm -f /tmp/staging_tenants.txt /tmp/prod_needed_tenants.txt /tmp/missing_tenants.txt /tmp/missing_tenants_export.csv

unset PGPASSWORD
