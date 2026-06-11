<?php

declare(strict_types=1);

use App\Models\Market;
use App\Services\Debt\DebtDecisionPolicy;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Market::query()
            ->whereNotNull('settings')
            ->each(function (Market $market): void {
                $settings = is_array($market->settings) ? $market->settings : [];
                $debtMonitoring = is_array($settings['debt_monitoring'] ?? null)
                    ? $settings['debt_monitoring']
                    : [];

                if (($debtMonitoring['settlement_map_aging_policy'] ?? null) !== DebtDecisionPolicy::AGING_SETTLEMENT_DOCUMENT_INVOICE_DAY) {
                    return;
                }

                $debtMonitoring['settlement_map_aging_policy'] = DebtDecisionPolicy::AGING_SETTLEMENT_NET_BALANCE;
                $settings['debt_monitoring'] = $debtMonitoring;

                $market->forceFill(['settings' => $settings])->save();
            });
    }

    public function down(): void
    {
        Market::query()
            ->whereNotNull('settings')
            ->each(function (Market $market): void {
                $settings = is_array($market->settings) ? $market->settings : [];
                $debtMonitoring = is_array($settings['debt_monitoring'] ?? null)
                    ? $settings['debt_monitoring']
                    : [];

                if (($debtMonitoring['settlement_map_aging_policy'] ?? null) !== DebtDecisionPolicy::AGING_SETTLEMENT_NET_BALANCE) {
                    return;
                }

                $debtMonitoring['settlement_map_aging_policy'] = DebtDecisionPolicy::AGING_SETTLEMENT_DOCUMENT_INVOICE_DAY;
                $settings['debt_monitoring'] = $debtMonitoring;

                $market->forceFill(['settings' => $settings])->save();
            });
    }
};
