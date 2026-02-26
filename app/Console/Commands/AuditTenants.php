<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Tenant;

class AuditTenants extends Command
{
    protected $signature = 'tenants:audit';

    protected $description = 'Audit tenants data for duplicates and data quality issues';

    public function handle()
    {
        $this->info('--- Tenants Audit ---');

        $total = Tenant::count();

        $this->line("Total tenants: {$total}");

        return self::SUCCESS;
    }
}