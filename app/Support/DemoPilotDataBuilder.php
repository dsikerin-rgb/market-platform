<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

class DemoPilotDataBuilder
{
    public function __construct(
        private readonly DemoPilotSettings $settings,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(?string $marketSlug = null, ?string $emailDomain = null): array
    {
        $marketSlug = $this->normalizeSlug($marketSlug ?: $this->settings->marketSlug());
        $emailDomain = $this->normalizeEmailDomain($emailDomain ?: $this->settings->emailDomain());
        $source = $this->settings->syntheticSource();

        return [
            'metadata' => [
                'version' => 'demo-pilot-v1',
                'synthetic_source' => $source,
                'market_slug' => $marketSlug,
                'email_domain' => $emailDomain,
                'external_integrations_enabled' => false,
            ],
            'market' => $this->market($marketSlug, $source),
            'users' => $this->users($marketSlug, $emailDomain, $source),
            'locations' => $this->locations($source),
            'spaces' => $this->spaces($source),
            'tenants' => $this->tenants($emailDomain, $source),
            'contracts' => $this->contracts($source),
            'accruals' => $this->accruals($source),
            'payments' => $this->payments($source),
            'marketplace_categories' => $this->marketplaceCategories($source),
            'marketplace_products' => $this->marketplaceProducts($source),
            'announcements' => $this->announcements($source),
            'integrations' => [
                'one_c' => 'disabled',
                'mail' => 'disabled',
                'telegram' => 'disabled',
                'webhooks' => 'disabled',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $dataSet
     * @return array<string, int>
     */
    public function counts(array $dataSet): array
    {
        $counts = [];

        foreach ($dataSet as $section => $payload) {
            if ($section === 'metadata' || $section === 'integrations') {
                continue;
            }

            $counts[$section] = array_is_list($payload) ? count($payload) : 1;
        }

        return $counts;
    }

    /**
     * @return array<string, mixed>
     */
    private function market(string $marketSlug, string $source): array
    {
        return [
            'key' => 'market',
            'name' => 'Демо-рынок Центральный',
            'slug' => $marketSlug,
            'code' => Str::upper(Str::replace('-', '_', $marketSlug)),
            'address' => 'г. Новосибирск, ул. Рыночная, 1',
            'timezone' => 'Asia/Novosibirsk',
            'is_active' => true,
            'settings' => [
                'demo_pilot' => [
                    'synthetic_source' => $source,
                    'external_integrations_enabled' => false,
                ],
            ],
            'features' => [
                'marketplace' => true,
                'documents' => true,
                'tasks' => true,
                'one_c_import' => false,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function users(string $marketSlug, string $emailDomain, string $source): array
    {
        return [
            $this->user('director', 'Анна Волкова', 'director', $emailDomain, $source),
            $this->user('admin', 'Ирина Смирнова', 'admin', $emailDomain, $source),
            $this->user('operator', 'Павел Орлов', 'operator', $emailDomain, $source),
            array_merge($this->user('tenant-user', 'Мария Кузнецова', 'tenant', $emailDomain, $source), [
                'tenant_key' => 'tenant-grocery',
            ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function user(string $key, string $name, string $role, string $emailDomain, string $source): array
    {
        return [
            'key' => 'user-' . $key,
            'name' => $name,
            'email' => $key . '@' . $emailDomain,
            'role' => $role,
            'password_strategy' => 'generated_on_provision',
            'synthetic_source' => $source,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function locations(string $source): array
    {
        return [
            [
                'key' => 'location-main-hall',
                'name' => 'Основной павильон',
                'code' => 'main-hall',
                'type' => 'hall',
                'sort_order' => 10,
                'is_active' => true,
                'synthetic_source' => $source,
            ],
            [
                'key' => 'location-food-court',
                'name' => 'Фуд-корт',
                'code' => 'food-court',
                'type' => 'food',
                'sort_order' => 20,
                'is_active' => true,
                'synthetic_source' => $source,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function spaces(string $source): array
    {
        return [
            $this->space('space-a-01', 'location-main-hall', 'A-01', 'Овощная лавка', 'tenant-produce', 18.5, 2150, 'occupied', $source),
            $this->space('space-a-02', 'location-main-hall', 'A-02', 'Продукты у дома', 'tenant-grocery', 22.0, 2400, 'occupied', $source),
            $this->space('space-b-01', 'location-food-court', 'B-01', 'Кофейная точка', 'tenant-coffee', 12.0, 3100, 'occupied', $source),
            $this->space('space-b-02', 'location-food-court', 'B-02', 'Пекарня', 'tenant-bakery', 14.0, 2950, 'occupied', $source),
            $this->space('space-a-03', 'location-main-hall', 'A-03', 'Свободное место', null, 16.0, 2100, 'vacant', $source),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function space(
        string $key,
        string $locationKey,
        string $number,
        string $displayName,
        ?string $tenantKey,
        float $area,
        int $rentRate,
        string $status,
        string $source,
    ): array {
        return [
            'key' => $key,
            'location_key' => $locationKey,
            'tenant_key' => $tenantKey,
            'number' => $number,
            'code' => Str::slug($number),
            'display_name' => $displayName,
            'activity_type' => 'retail',
            'area_sqm' => $area,
            'rent_rate_value' => $rentRate,
            'rent_rate_unit' => 'sqm_month',
            'type' => 'retail',
            'status' => $status,
            'is_active' => true,
            'synthetic_source' => $source,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tenants(string $emailDomain, string $source): array
    {
        return [
            $this->tenant('tenant-produce', 'ООО "Свежие овощи"', 'demo-produce', 'produce@' . $emailDomain, 'green', $source),
            $this->tenant('tenant-grocery', 'ООО "Продукты у дома"', 'demo-grocery', 'grocery@' . $emailDomain, 'orange', $source),
            $this->tenant('tenant-coffee', 'ООО "Кофе на рынке"', 'demo-coffee', 'coffee@' . $emailDomain, 'green', $source),
            $this->tenant('tenant-bakery', 'ООО "Ремесленная пекарня"', 'demo-bakery', 'bakery@' . $emailDomain, 'red', $source),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function tenant(string $key, string $name, string $slug, string $email, string $debtStatus, string $source): array
    {
        return [
            'key' => $key,
            'name' => $name,
            'short_name' => $name,
            'slug' => $slug,
            'type' => 'llc',
            'external_id' => 'demo-' . $key,
            'inn' => null,
            'phone' => '+70000000000',
            'email' => $email,
            'contact_person' => 'Иван Петров',
            'status' => 'active',
            'is_active' => true,
            'debt_status' => $debtStatus,
            'one_c_data' => [
                'synthetic_source' => $source,
                'live_1c' => false,
            ],
            'synthetic_source' => $source,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function contracts(string $source): array
    {
        return [
            $this->contract('contract-produce', 'tenant-produce', 'space-a-01', 39775, $source),
            $this->contract('contract-grocery', 'tenant-grocery', 'space-a-02', 52800, $source),
            $this->contract('contract-coffee', 'tenant-coffee', 'space-b-01', 37200, $source),
            $this->contract('contract-bakery', 'tenant-bakery', 'space-b-02', 41300, $source),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function contract(string $key, string $tenantKey, string $spaceKey, int $monthlyRent, string $source): array
    {
        return [
            'key' => $key,
            'external_id' => 'demo-' . $key,
            'tenant_key' => $tenantKey,
            'market_space_key' => $spaceKey,
            'number' => 'D-' . Str::upper(Str::after($key, 'contract-')),
            'status' => 'active',
            'starts_at' => '2026-01-01',
            'ends_at' => null,
            'signed_at' => '2025-12-15',
            'monthly_rent' => $monthlyRent,
            'currency' => 'RUB',
            'is_active' => true,
            'space_mapping_mode' => 'manual',
            'synthetic_source' => $source,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function accruals(string $source): array
    {
        return [
            $this->accrual('accrual-produce', 'tenant-produce', 'contract-produce', 'space-a-01', 39775, 0, $source),
            $this->accrual('accrual-grocery', 'tenant-grocery', 'contract-grocery', 'space-a-02', 52800, 9500, $source),
            $this->accrual('accrual-coffee', 'tenant-coffee', 'contract-coffee', 'space-b-01', 37200, 0, $source),
            $this->accrual('accrual-bakery', 'tenant-bakery', 'contract-bakery', 'space-b-02', 41300, 28000, $source),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function accrual(
        string $key,
        string $tenantKey,
        string $contractKey,
        string $spaceKey,
        int $rentAmount,
        int $cashAmount,
        string $source,
    ): array {
        return [
            'key' => $key,
            'tenant_key' => $tenantKey,
            'tenant_contract_key' => $contractKey,
            'market_space_key' => $spaceKey,
            'period' => '2026-06-01',
            'document_date' => '2026-06-30',
            'rent_amount' => $rentAmount,
            'management_fee' => 0,
            'utilities_amount' => 0,
            'electricity_amount' => 0,
            'total_no_vat' => $rentAmount,
            'vat_rate' => 0,
            'total_with_vat' => $rentAmount,
            'cash_amount' => $cashAmount,
            'imported_at' => '2026-06-30 08:00:00',
            'synthetic_source' => $source,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function payments(string $source): array
    {
        return [
            $this->payment('payment-produce', 'tenant-produce', 'contract-produce', 39775, $source),
            $this->payment('payment-grocery', 'tenant-grocery', 'contract-grocery', 43300, $source),
            $this->payment('payment-coffee', 'tenant-coffee', 'contract-coffee', 37200, $source),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payment(string $key, string $tenantKey, string $contractKey, int $amount, string $source): array
    {
        return [
            'key' => $key,
            'tenant_key' => $tenantKey,
            'tenant_contract_key' => $contractKey,
            'payment_date' => '2026-06-25',
            'period' => '2026-06-01',
            'amount' => $amount,
            'payload' => [
                'synthetic_source' => $source,
            ],
            'imported_at' => '2026-06-30 08:05:00',
            'synthetic_source' => $source,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function marketplaceCategories(string $source): array
    {
        return [
            $this->category('category-produce', 'Овощи и фрукты', 'produce', 10, $source),
            $this->category('category-grocery', 'Бакалея', 'grocery', 20, $source),
            $this->category('category-ready-food', 'Готовая еда', 'ready-food', 30, $source),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function category(string $key, string $name, string $slug, int $sortOrder, string $source): array
    {
        return [
            'key' => $key,
            'name' => $name,
            'slug' => $slug,
            'sort_order' => $sortOrder,
            'is_active' => true,
            'synthetic_source' => $source,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function marketplaceProducts(string $source): array
    {
        return [
            $this->product('product-apples', 'tenant-produce', 'space-a-01', 'category-produce', 'Яблоки фермерские', 'farm-apples', 180, 'кг', $source),
            $this->product('product-honey', 'tenant-grocery', 'space-a-02', 'category-grocery', 'Мед цветочный', 'demo-honey-jar', 420, 'шт', $source),
            $this->product('product-coffee', 'tenant-coffee', 'space-b-01', 'category-ready-food', 'Кофе свежесваренный', 'fresh-coffee', 190, 'стакан', $source),
            $this->product('product-bread', 'tenant-bakery', 'space-b-02', 'category-ready-food', 'Хлеб ремесленный', 'bakery-bread', 120, 'шт', $source),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function product(
        string $key,
        string $tenantKey,
        string $spaceKey,
        string $categoryKey,
        string $title,
        string $slug,
        int $price,
        string $unit,
        string $source,
    ): array {
        return [
            'key' => $key,
            'tenant_key' => $tenantKey,
            'market_space_key' => $spaceKey,
            'category_key' => $categoryKey,
            'title' => $title,
            'slug' => $slug,
            'description' => 'Демо-товар для витрины маркетплейса.',
            'price' => $price,
            'currency' => 'RUB',
            'stock_qty' => 25,
            'sku' => Str::upper(Str::replace('-', '_', $key)),
            'unit' => $unit,
            'images' => [],
            'attributes' => [
                'synthetic_source' => $source,
            ],
            'is_active' => true,
            'is_featured' => true,
            'is_demo' => true,
            'synthetic_source' => $source,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function announcements(string $source): array
    {
        return [
            [
                'key' => 'announcement-opening-weekend',
                'kind' => 'promo',
                'title' => 'Ярмарка выходного дня',
                'slug' => 'demo-weekend-market',
                'excerpt' => 'Демо-анонс акции для посетителей рынка.',
                'content' => 'Демо-контент для проверки витрины. Внешняя публикация не выполняется.',
                'starts_at' => '2026-06-01 09:00:00',
                'ends_at' => '2026-06-30 21:00:00',
                'is_active' => true,
                'published_at' => '2026-06-01 09:00:00',
                'synthetic_source' => $source,
            ],
        ];
    }

    private function normalizeSlug(string $slug): string
    {
        return Str::slug($slug) ?: 'demo-market';
    }

    private function normalizeEmailDomain(string $domain): string
    {
        $domain = Str::lower(trim($domain));
        $domain = ltrim($domain, '@');

        return $domain !== '' ? $domain : 'demo.marketuchet.local';
    }
}
