<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // 1) Заполняем code/slug там, где они отсутствуют
        $markets = DB::table('markets')
            ->select('id', 'name', 'code', 'slug')
            ->orderBy('id')
            ->get();

        $usedCodes = DB::table('markets')
            ->whereNotNull('code')
            ->pluck('code')
            ->map(fn ($v) => (string) $v)
            ->all();

        $usedSlugs = DB::table('markets')
            ->whereNotNull('slug')
            ->pluck('slug')
            ->map(fn ($v) => (string) $v)
            ->all();

        $usedCodes = array_flip($usedCodes);
        $usedSlugs = array_flip($usedSlugs);

        foreach ($markets as $market) {
            $code = $market->code ? (string) $market->code : '';
            $slug = $market->slug ? (string) $market->slug : '';

            if ($code === '') {
                $base = Str::slug((string) $market->name, '-');
                $base = Str::lower($base);

                if ($base === '') {
                    $base = 'market';
                }

                $candidate = $base;
                $i = 1;

                while (isset($usedCodes[$candidate])) {
                    $i++;
                    $candidate = $base . '-' . $i;
                }

                $code = $candidate;
                $usedCodes[$code] = true;
            }

            if ($slug === '') {
                // По умолчанию slug = code
                $candidate = $code;
                $base = $candidate;
                $i = 1;

                while (isset($usedSlugs[$candidate])) {
                    $i++;
                    $candidate = $base . '-' . $i;
                }

                $slug = $candidate;
                $usedSlugs[$slug] = true;
            }

            DB::table('markets')
                ->where('id', $market->id)
                ->update([
                    'code' => $code,
                    'slug' => $slug,
                ]);
        }

        // 2) Добавляем уникальные индексы (SQLite поддерживает)
        Schema::table('markets', function (Blueprint $table) {
            $table->unique('code', 'markets_code_unique');
            $table->unique('slug', 'markets_slug_unique');
        });
    }

    public function down(): void
    {
        Schema::table('markets', function (Blueprint $table) {
            $table->dropUnique('markets_code_unique');
            $table->dropUnique('markets_slug_unique');
        });
    }
};
