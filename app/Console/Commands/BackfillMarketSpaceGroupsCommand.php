<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MarketSpace;
use App\Services\MarketSpaces\SpaceGroupResolver;
use Illuminate\Console\Command;

class BackfillMarketSpaceGroupsCommand extends Command
{
    protected $signature = 'market-spaces:backfill-groups
        {--market= : Ограничить одним market_id}
        {--limit=50 : Сколько примеров показать в dry-run}
        {--execute : Применить изменения}';

    protected $description = 'Заполняет группы мест и номера внутри группы для уже существующих market_spaces по очевидным шаблонам номера.';

    public function handle(SpaceGroupResolver $resolver): int
    {
        $marketId = $this->option('market');
        $limit = max(1, (int) $this->option('limit'));
        $execute = (bool) $this->option('execute');

        $query = MarketSpace::query()
            ->when(filled($marketId), fn ($query) => $query->where('market_id', (int) $marketId))
            ->orderBy('id');

        $matched = 0;
        $changed = 0;
        $examples = [];

        $query->chunkById(200, function ($spaces) use ($resolver, $execute, $limit, &$matched, &$changed, &$examples): void {
            foreach ($spaces as $space) {
                $derived = $resolver->forMarketSpaceNumber($space->number);
                $groupToken = $derived['group_token'];
                $groupSegments = $derived['group_segments'];

                if (! $groupToken || ! $groupSegments) {
                    continue;
                }

                $matched++;

                $currentToken = $resolver->normalizeGroupToken($space->space_group_token);
                $currentSegments = $resolver->normalizeGroupSlot($space->space_group_slot);

                if ($currentToken === $groupToken && $currentSegments === $groupSegments) {
                    continue;
                }

                $changed++;

                if (count($examples) < $limit) {
                    $examples[] = [
                        'id' => (int) $space->id,
                        'number' => (string) $space->number,
                        'from' => trim((string) ($space->space_group_token ?? '')) . ' / ' . trim((string) ($space->space_group_slot ?? '')),
                        'to' => $groupToken . ' / ' . $groupSegments,
                    ];
                }

                if ($execute) {
                    $space->forceFill([
                        'space_group_token' => $groupToken,
                        'space_group_slot' => $groupSegments,
                    ])->save();
                }
            }
        });

        $this->info('Распознано мест по шаблону: ' . $matched);
        $this->info(($execute ? 'Изменено' : 'К изменению') . ': ' . $changed);

        if ($examples !== []) {
            $this->newLine();
            $this->table(
                ['ID', 'Номер места', 'Сейчас', 'Будет'],
                array_map(static fn (array $row): array => [
                    $row['id'],
                    $row['number'],
                    $row['from'],
                    $row['to'],
                ], $examples),
            );
        }

        if (! $execute) {
            $this->newLine();
            $this->line('Для применения запустите команду с флагом --execute.');
        }

        return self::SUCCESS;
    }
}
