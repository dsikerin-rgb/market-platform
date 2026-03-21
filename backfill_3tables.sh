#!/bin/bash
set -euo pipefail

echo "=== PROD: CREATE CUSTOM DATA-ONLY DUMP ==="
cd /var/www/market/current
mkdir -p /var/www/market/backups

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

PROD_DUMP="/var/www/market/backups/prod_financial_backfill_custom_$(date +%F_%H-%M-%S).dump"

echo "=== PROD DB TARGET ==="
psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -Atc "
select current_database() || ' | size=' || pg_size_pretty(pg_database_size(current_database())) || ' | checked_at=' || now();
"

pg_dump \
  -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" \
  -Fc \
  --data-only \
  --no-owner \
  --no-privileges \
  --table=public.tenant_contracts \
  --table=public.contract_debts \
  --table=public.tenant_accruals \
  -f "$PROD_DUMP"

echo "=== PROD DUMP FILE ==="
ls -lh "$PROD_DUMP"
sha256sum "$PROD_DUMP"

unset PGPASSWORD DB_HOST DB_PORT DB_NAME DB_USER

echo ""
echo "=== STAGING: BACKUP BARRIER ==="
cd /var/www/market-staging/current
mkdir -p /var/www/market-staging/backups

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

STAGING_BACKUP="/var/www/market-staging/backups/staging_before_custom_3table_backfill_$(date +%F_%H-%M-%S).dump"

echo "=== STAGING DB TARGET ==="
psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -Atc "
select current_database() || ' | size=' || pg_size_pretty(pg_database_size(current_database())) || ' | checked_at=' || now();
"

echo "=== STAGING PRE-CHECK COUNTS ==="
psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -Atc "
select 'tenant_contracts|' || count(*) from tenant_contracts
union all
select 'contract_debts|' || count(*) from contract_debts
union all
select 'tenant_accruals|' || count(*) from tenant_accruals
order by 1;
"

pg_dump -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -Fc -f "$STAGING_BACKUP"

echo "=== STAGING BACKUP FILE ==="
ls -lh "$STAGING_BACKUP"
sha256sum "$STAGING_BACKUP"

echo ""
echo "=== STAGING: TRUNCATE + RESTORE ==="
echo "=== SOURCE PROD DUMP CHECK ==="
ls -lh "$PROD_DUMP"
sha256sum "$PROD_DUMP"

echo "=== TRUNCATE 3 TABLES ==="
psql -v ON_ERROR_STOP=1 -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -c "
TRUNCATE TABLE tenant_accruals, contract_debts, tenant_contracts RESTART IDENTITY CASCADE;
"

echo "=== RESTORE 3 TABLES FROM CUSTOM DUMP ==="
pg_restore \
  -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" \
  --data-only \
  --single-transaction \
  --exit-on-error \
  --no-owner \
  --no-privileges \
  "$PROD_DUMP"

echo "=== RESET SEQUENCES ==="
psql -v ON_ERROR_STOP=1 -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -c "
SELECT setval('tenant_contracts_id_seq', COALESCE((SELECT MAX(id) FROM tenant_contracts), 1), true);
SELECT setval('contract_debts_id_seq', COALESCE((SELECT MAX(id) FROM contract_debts), 1), true);
SELECT setval('tenant_accruals_id_seq', COALESCE((SELECT MAX(id) FROM tenant_accruals), 1), true);
"

echo "=== STAGING POST-CHECK COUNTS ==="
psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -Atc "
select 'tenant_contracts|' || count(*) from tenant_contracts
union all
select 'contract_debts|' || count(*) from contract_debts
union all
select 'tenant_accruals|' || count(*) from tenant_accruals
order by 1;
"

echo "=== SEQUENCE VALUES ==="
psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -Atc "
select 'tenant_contracts_id_seq|' || last_value from tenant_contracts_id_seq
union all
select 'contract_debts_id_seq|' || last_value from contract_debts_id_seq
union all
select 'tenant_accruals_id_seq|' || last_value from tenant_accruals_id_seq
order by 1;
"

echo "=== FK ORPHAN CHECK ==="
psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -Atc "
select 'contracts_missing_tenant|' || count(*)
from tenant_contracts tc
left join tenants t on t.id = tc.tenant_id
where tc.tenant_id is not null and t.id is null
union all
select 'contracts_missing_space|' || count(*)
from tenant_contracts tc
left join market_spaces ms on ms.id = tc.market_space_id
where tc.market_space_id is not null and ms.id is null
union all
select 'contracts_missing_user|' || count(*)
from tenant_contracts tc
left join users u on u.id = tc.space_mapping_updated_by_user_id
where tc.space_mapping_updated_by_user_id is not null and u.id is null
union all
select 'accruals_missing_tenant|' || count(*)
from tenant_accruals ta
left join tenants t on t.id = ta.tenant_id
where ta.tenant_id is not null and t.id is null
union all
select 'accruals_missing_space|' || count(*)
from tenant_accruals ta
left join market_spaces ms on ms.id = ta.market_space_id
where ta.market_space_id is not null and ms.id is null
union all
select 'accruals_missing_contract|' || count(*)
from tenant_accruals ta
left join tenant_contracts tc on tc.id = ta.tenant_contract_id
where ta.tenant_contract_id is not null and tc.id is null
order by 1;
"

echo "=== CONTROL SAMPLES ==="
psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -Atc "
select 'tenant_contracts_sample|' || id || '|' || coalesce(number,'') || '|' || tenant_id || '|' || coalesce(market_space_id::text,'')
from tenant_contracts
order by id
limit 5;
"
psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -Atc "
select 'contract_debts_sample|' || id || '|' || coalesce(contract_external_id,'') || '|' || coalesce(tenant_id::text,'') || '|' || coalesce(debt_amount::text,'')
from contract_debts
order by id
limit 5;
"
psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -Atc "
select 'tenant_accruals_sample|' || id || '|' || coalesce(tenant_contract_id::text,'') || '|' || coalesce(tenant_id::text,'') || '|' || coalesce(accrued_amount::text,'')
from tenant_accruals
order by id
limit 5;
"

unset PGPASSWORD DB_HOST DB_PORT DB_NAME DB_USER
