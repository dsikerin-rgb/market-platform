#!/bin/bash
set -euo pipefail

echo "=== STAGING: RESTORE MISSING TENANTS FIRST ==="
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

MISSING_TENANTS_DUMP="/var/www/market/backups/prod_missing_tenants_custom_2026-03-15_21-20-04.dump"
PROD_3TABLE_DUMP="/var/www/market/backups/prod_financial_backfill_custom_2026-03-15_21-19-20.dump"

echo "=== RESTORE MISSING TENANTS ==="
psql -v ON_ERROR_STOP=1 -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -c "
SET CONSTRAINTS ALL DEFERRED;
"

pg_restore \
  -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" \
  --data-only \
  --single-transaction \
  --exit-on-error \
  --no-owner \
  --no-privileges \
  "$MISSING_TENANTS_DUMP"

echo "=== VERIFY TENANTS COUNT ==="
psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -Atc "select 'tenants_count=' || count(*) from tenants;"

echo ""
echo "=== NOW RESTORE 3 FINANCIAL TABLES ==="
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
  "$PROD_3TABLE_DUMP"

echo "=== RESET SEQUENCES ==="
psql -v ON_ERROR_STOP=1 -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -c "
SELECT setval('tenant_contracts_id_seq', COALESCE((SELECT MAX(id) FROM tenant_contracts), 1), true);
SELECT setval('contract_debts_id_seq', COALESCE((SELECT MAX(id) FROM contract_debts), 1), true);
SELECT setval('tenant_accruals_id_seq', COALESCE((SELECT MAX(id) FROM tenant_accruals), 1), true);
SELECT setval('tenants_id_seq', COALESCE((SELECT MAX(id) FROM tenants), 1), true);
"

echo "=== STAGING POST-CHECK COUNTS ==="
psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -Atc "
select 'tenants|' || count(*) from tenants
union all
select 'tenant_contracts|' || count(*) from tenant_contracts
union all
select 'contract_debts|' || count(*) from contract_debts
union all
select 'tenant_accruals|' || count(*) from tenant_accruals
order by 1;
"

echo "=== SEQUENCE VALUES ==="
psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -Atc "
select 'tenants_id_seq|' || last_value from tenants_id_seq
union all
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
select 'tenants_sample|' || id || '|' || coalesce(name,'')
from tenants
where id >= 359
order by id
limit 5;
"
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

echo ""
echo "=== FINAL VERDICT ==="
psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -Atc "
select 
  case when count(*) = 0 then 'FK_CHECK=PASS' else 'FK_CHECK=FAIL' end as fk_check
from (
  select tc.tenant_id from tenant_contracts tc left join tenants t on t.id = tc.tenant_id where tc.tenant_id is not null and t.id is null
  union all
  select tc.market_space_id from tenant_contracts tc left join market_spaces ms on ms.id = tc.market_space_id where tc.market_space_id is not null and ms.id is null
  union all
  select ta.tenant_id from tenant_accruals ta left join tenants t on t.id = ta.tenant_id where ta.tenant_id is not null and t.id is null
  union all
  select ta.market_space_id from tenant_accruals ta left join market_spaces ms on ms.id = ta.market_space_id where ta.market_space_id is not null and ms.id is null
  union all
  select ta.tenant_contract_id from tenant_accruals ta left join tenant_contracts tc on tc.id = ta.tenant_contract_id where ta.tenant_contract_id is not null and tc.id is null
) orphans;
"

unset PGPASSWORD DB_HOST DB_PORT DB_NAME DB_USER
