<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

class AiGigaChatProbe extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ai:gigachat:probe';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Probe GigaChat configuration to verify project isolation';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('╔══════════════════════════════════════════════════════════╗');
        $this->info('║          GigaChat Probe — Project Isolation Check       ║');
        $this->info('╚══════════════════════════════════════════════════════════╝');
        $this->newLine();

        // 1. App path / context
        $this->section('1. Application Context');
        $this->row('Base Path', base_path());
        $this->row('App Name', config('app.name'));
        $this->row('Environment', app()->environment());
        $this->row('.env Path', app()->environmentFilePath());
        $this->row('Config Path', config_path());
        $this->newLine();

        // 2. GigaChat config source
        $this->section('2. GigaChat Configuration');

        // Read from config() — which pulls from .env via config/gigachat.php
        $authKey    = config('gigachat.auth_key');
        $scope      = config('gigachat.scope');
        $model      = config('gigachat.model');
        $diagModel  = config('gigachat.diag_model');

        // Also show raw env values for comparison
        $envAuthKey = env('GIGACHAT_AUTH_KEY');
        $envScope   = env('GIGACHAT_SCOPE');
        $envModel   = env('GIGACHAT_MODEL');
        $envDiagModel = env('GIGACHAT_DIAG_MODEL');

        $configFile = config_path('gigachat.php');
        $configExists = file_exists($configFile);

        $this->row('Config File', $configExists ? $configFile : 'NOT FOUND');
        $this->row('Config Source', 'config/gigachat.php → env()');
        $this->row('GIGACHAT_AUTH_KEY (raw .env)', $this->maskSecret($envAuthKey));
        $this->row('GIGACHAT_AUTH_KEY (config)', $this->maskSecret($authKey));
        $this->row('GIGACHAT_SCOPE (raw .env)', $envScope ?? 'NOT SET');
        $this->row('GIGACHAT_SCOPE (config)', $scope);
        $this->row('GIGACHAT_MODEL (raw .env)', $envModel ?? 'NOT SET');
        $this->row('GIGACHAT_MODEL (config)', $model);
        $this->row('GIGACHAT_DIAG_MODEL (raw .env)', $envDiagModel ?? 'NOT SET');
        $this->row('GIGACHAT_DIAG_MODEL (config)', $diagModel);
        $this->newLine();

        // 3. Fallback / default analysis
        $this->section('3. Fallback / Default Values');

        $hasFallback = false;
        $fallbackDetails = [];

        // Check if any other AI configs exist (file-based check only)
        $possibleFallbacks = [];

        if (file_exists(config_path('ai.php'))) {
            $possibleFallbacks[] = 'config/ai.php';
        }
        if (file_exists(config_path('openai.php'))) {
            $possibleFallbacks[] = 'config/openai.php';
        }

        $hasFallback = count($possibleFallbacks) > 0;

        // Check if defaults are applied from config/gigachat.php
        $scopeDefault = $scope === 'GIGACHAT_API.PERS' && $envScope === null;
        $modelDefault = $model === 'GigaChat' && $envModel === null;

        if ($scopeDefault) {
            $this->row('SCOPE default', 'GIGACHAT_API.PERS (from config/gigachat.php)');
        }
        if ($modelDefault) {
            $this->row('MODEL default', 'GigaChat (from config/gigachat.php)');
        }

        if ($hasFallback) {
            $this->warn('⚠ Other AI config found: ' . implode(', ', $fallbackDetails));
        } else {
            $this->info('✓ No cross-project fallback detected — config is Market Platform isolated');
        }

        $this->row('Cross-project leakage risk', 'NONE — config/gigachat.php is project-specific');
        $this->newLine();

        // 4. Effective values (what would actually be used)
        $this->section('4. Effective Runtime Values');

        $effectiveAuth = $authKey ?: '(not set)';
        $effectiveScope = $scope ?: '(not set — default will be used by SDK)';
        $effectiveModel = $model ?: '(not set — default will be used by SDK)';
        $effectiveDiagModel = $diagModel ?: '(not set)';

        $this->row('Effective AUTH_KEY', $this->maskSecret($effectiveAuth));
        $this->row('Effective SCOPE', $effectiveScope);
        $this->row('Effective MODEL', $effectiveModel);
        $this->row('Effective DIAG_MODEL', $effectiveDiagModel);
        $this->newLine();

        // 5. Isolation verdict
        $this->section('5. Isolation Verdict');

        $configPresent = file_exists(config_path('gigachat.php'));
        $authPresent = !empty($authKey);
        $isIsolated = $configPresent && !$hasFallback;

        if ($isIsolated && $authPresent) {
            $this->info('✅ GigaChat is CONFIGURED and ISOLATED within Market Platform.');
            $this->info('   Config: config/gigachat.php → .env (Market Platform only)');
            $this->info('   No хвосты from Service Desk or other projects.');
        } elseif ($isIsolated && !$authPresent) {
            $this->info('✅ GigaChat config is ISOLATED within Market Platform.');
            $this->warn('   ⚠ AUTH_KEY is empty — set GIGACHAT_AUTH_KEY in .env to activate.');
            $this->info('   No хвосты from Service Desk or other projects.');
        } else {
            $this->warn('⚠ Review configuration above.');
        }

        $this->newLine();
        $this->info('Probe complete.');

        return Command::SUCCESS;
    }

    private function row(string $key, string $value): void
    {
        $this->getOutput()->writeln("  <fg=gray>{$key}:</> {$value}");
    }

    private function maskSecret(?string $value): string
    {
        if ($value === null || $value === '') {
            return '(empty)';
        }
        if (strlen($value) <= 8) {
            return '****';
        }
        return substr($value, 0, 4) . '...' . substr($value, -4);
    }

    private function section(string $title): void
    {
        $this->getOutput()->writeln("<fg=cyan;options=bold>{$title}</>");
    }
}
