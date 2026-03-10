<?php
# database/migrations/2026_03_08_000000_init_market_debt_monitoring_settings.php

use App\Models\Market;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $markets = Market::all();
        
        foreach ($markets as $market) {
            $settings = $market->settings ?? [];
            
            // Инициализируем настройки мониторинга задолженности по умолчанию
            if (!isset($settings['debt_monitoring'])) {
                $settings['debt_monitoring'] = [
                    'grace_days' => 5,
                    'yellow_after_days' => 1,
                    'red_after_days' => 30,
                    'tenant_aggregate_mode' => 'worst',
                ];
                
                $market->settings = $settings;
                $market->save();
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $markets = Market::all();
        
        foreach ($markets as $market) {
            $settings = $market->settings ?? [];
            
            if (isset($settings['debt_monitoring'])) {
                unset($settings['debt_monitoring']);
                $market->settings = $settings;
                $market->save();
            }
        }
    }
};
