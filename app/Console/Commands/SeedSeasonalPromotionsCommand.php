<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Market;
use App\Models\MarketHoliday;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class SeedSeasonalPromotionsCommand extends Command
{
    protected $signature = 'market:holidays:seed-promotions
        {--market= : Market id or slug}
        {--overwrite : Update existing promotions with same title/date}
        {--no-images : Create promotions without external photo urls}';

    protected $description = 'Seed seasonal promo events for the next month';

    public function handle(): int
    {
        $markets = $this->resolveMarkets();
        if ($markets->isEmpty()) {
            $this->warn('No active markets found.');

            return self::SUCCESS;
        }

        $now = now();
        $overwrite = (bool) $this->option('overwrite');
        $useImages = ! (bool) $this->option('no-images');

        $total = 0;

        foreach ($markets as $market) {
            $templates = $this->seasonalTemplates($now);
            $this->line(sprintf('Market: %s (#%d)', $market->name, (int) $market->id));

            foreach ($templates as $template) {
                $start = $this->resolveStartDate($now, (int) $template['offset_days']);
                $end = $start->copy()->addDays((int) $template['duration_days']);

                $payload = [
                    'market_id' => (int) $market->id,
                    'title' => (string) $template['title'],
                    'starts_at' => $start->toDateString(),
                    'ends_at' => $end->toDateString(),
                    'all_day' => true,
                    'description' => (string) $template['description'],
                    'notify_before_days' => (int) ($template['notify_before_days'] ?? 7),
                    'source' => 'promotion',
                    'cover_image' => $useImages ? (string) ($template['image'] ?? '') : null,
                ];

                if ($overwrite) {
                    MarketHoliday::query()->updateOrCreate(
                        [
                            'market_id' => (int) $market->id,
                            'title' => (string) $template['title'],
                            'starts_at' => $start->toDateString(),
                            'source' => 'promotion',
                        ],
                        $payload,
                    );
                } else {
                    $exists = MarketHoliday::query()
                        ->where('market_id', (int) $market->id)
                        ->where('title', (string) $template['title'])
                        ->whereDate('starts_at', $start->toDateString())
                        ->where('source', 'promotion')
                        ->exists();

                    if ($exists) {
                        continue;
                    }

                    MarketHoliday::query()->create($payload);
                }

                $total++;
            }
        }

        $this->info(sprintf('Seasonal promotions prepared: %d', $total));

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Market>
     */
    private function resolveMarkets(): Collection
    {
        $raw = trim((string) $this->option('market'));

        if ($raw === '') {
            return Market::query()->where('is_active', true)->orderBy('id')->get();
        }

        $query = Market::query()->where('is_active', true);

        if (is_numeric($raw)) {
            $query->whereKey((int) $raw);
        } else {
            $query->where('slug', $raw);
        }

        return $query->get();
    }

    private function resolveStartDate(Carbon $base, int $offsetDays): Carbon
    {
        return $base->copy()->startOfDay()->addDays($offsetDays);
    }

    /**
     * @return array<int, array{title:string,description:string,offset_days:int,duration_days:int,notify_before_days:int,image:string}>
     */
    private function seasonalTemplates(Carbon $now): array
    {
        $month = (int) $now->month;

        if (in_array($month, [3, 4, 5], true)) {
            return [
                [
                    'title' => 'Весенний ценопад',
                    'description' => 'Сезонные скидки на свежие продукты и товары для дома.',
                    'offset_days' => 2,
                    'duration_days' => 6,
                    'notify_before_days' => 3,
                    'image' => 'https://images.unsplash.com/photo-1488459716781-31db52582fe9?auto=format&fit=crop&w=1600&q=80',
                ],
                [
                    'title' => 'Неделя фермерских вкусов',
                    'description' => 'Дегустации и специальные предложения от фермерских точек рынка.',
                    'offset_days' => 9,
                    'duration_days' => 5,
                    'notify_before_days' => 4,
                    'image' => 'https://images.unsplash.com/photo-1498579809087-ef1e558fd1da?auto=format&fit=crop&w=1600&q=80',
                ],
                [
                    'title' => 'Выходные подарков и цветов',
                    'description' => 'Подборка подарков, цветов и праздничных наборов к весенним датам.',
                    'offset_days' => 15,
                    'duration_days' => 3,
                    'notify_before_days' => 5,
                    'image' => 'https://images.unsplash.com/photo-1464965911861-746a04b4bca6?auto=format&fit=crop&w=1600&q=80',
                ],
            ];
        }

        if (in_array($month, [6, 7, 8], true)) {
            return [
                [
                    'title' => 'Летняя ярмарка скидок',
                    'description' => 'Горячие предложения на сезонные товары и прохладительные напитки.',
                    'offset_days' => 2,
                    'duration_days' => 7,
                    'notify_before_days' => 3,
                    'image' => 'https://images.unsplash.com/photo-1472851294608-062f824d29cc?auto=format&fit=crop&w=1600&q=80',
                ],
                [
                    'title' => 'Фестиваль уличной еды',
                    'description' => 'Лучшие гастрономические предложения рынка в одном месте.',
                    'offset_days' => 10,
                    'duration_days' => 4,
                    'notify_before_days' => 4,
                    'image' => 'https://images.unsplash.com/photo-1529692236671-f1dcde7f1d1b?auto=format&fit=crop&w=1600&q=80',
                ],
                [
                    'title' => 'Семейный weekend на рынке',
                    'description' => 'Скидки, мастер-классы и развлечения для всей семьи.',
                    'offset_days' => 17,
                    'duration_days' => 2,
                    'notify_before_days' => 5,
                    'image' => 'https://images.unsplash.com/photo-1504754524776-8f4f37790ca0?auto=format&fit=crop&w=1600&q=80',
                ],
            ];
        }

        if (in_array($month, [9, 10, 11], true)) {
            return [
                [
                    'title' => 'Осенний сбор предложений',
                    'description' => 'Снижения цен на сезонные товары и заготовки.',
                    'offset_days' => 2,
                    'duration_days' => 7,
                    'notify_before_days' => 3,
                    'image' => 'https://images.unsplash.com/photo-1471193945509-9ad0617afabf?auto=format&fit=crop&w=1600&q=80',
                ],
                [
                    'title' => 'Тёплые выходные на рынке',
                    'description' => 'Уютные осенние предложения, акции и дегустации.',
                    'offset_days' => 10,
                    'duration_days' => 3,
                    'notify_before_days' => 4,
                    'image' => 'https://images.unsplash.com/photo-1506806732259-39c2d0268443?auto=format&fit=crop&w=1600&q=80',
                ],
                [
                    'title' => 'Неделя домашних заготовок',
                    'description' => 'Скидки на бакалею, специи и товары для хранения.',
                    'offset_days' => 17,
                    'duration_days' => 5,
                    'notify_before_days' => 5,
                    'image' => 'https://images.unsplash.com/photo-1542838132-92c53300491e?auto=format&fit=crop&w=1600&q=80',
                ],
            ];
        }

        return [
            [
                'title' => 'Зимний ценопад',
                'description' => 'Сезонные предложения на продукты, подарки и товары для дома.',
                'offset_days' => 2,
                'duration_days' => 8,
                'notify_before_days' => 3,
                'image' => 'https://images.unsplash.com/photo-1545239351-1141bd82e8a6?auto=format&fit=crop&w=1600&q=80',
            ],
            [
                'title' => 'Праздничная неделя',
                'description' => 'Спецпредложения к праздникам и семейным выходным.',
                'offset_days' => 12,
                'duration_days' => 6,
                'notify_before_days' => 4,
                'image' => 'https://images.unsplash.com/photo-1512389142860-9c449e58a543?auto=format&fit=crop&w=1600&q=80',
            ],
            [
                'title' => 'Ярмарка подарков',
                'description' => 'Подборка подарков и праздничных наборов от арендаторов.',
                'offset_days' => 20,
                'duration_days' => 4,
                'notify_before_days' => 5,
                'image' => 'https://images.unsplash.com/photo-1513885535751-8b9238bd345a?auto=format&fit=crop&w=1600&q=80',
            ],
        ];
    }
}

