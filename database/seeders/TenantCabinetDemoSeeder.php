<?php

namespace Database\Seeders;

use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\Tenant;
use App\Models\TenantAccrual;
use App\Models\TenantContract;
use App\Models\TenantDocument;
use App\Models\TenantShowcase;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class TenantCabinetDemoSeeder extends Seeder
{
    public function run(): void
    {
        $market = Market::query()->first();

        if (! $market) {
            $market = Market::create([
                'name' => 'Демо рынок',
                'slug' => 'demo-market',
                'code' => 'DEMO',
                'address' => 'г. Красноярск, ул. Рыночная, 1',
                'is_active' => true,
            ]);
        }

        $tenant = Tenant::query()->firstOrCreate([
            'name' => 'Фермерское хозяйство «ЭкоЯр»',
            'market_id' => $market->id,
        ], [
            'short_name' => 'ЭкоЯр',
            'slug' => 'ekoyar',
            'phone' => '+7 900 000-00-00',
            'email' => 'eco@example.com',
            'is_active' => true,
        ]);

        if (! $tenant->slug) {
            $tenant->slug = Str::slug($tenant->name) ?: 'tenant-' . $tenant->id;
            $tenant->save();
        }

        $user = User::query()->firstOrCreate([
            'email' => 'tenant@example.com',
        ], [
            'name' => 'Арендатор Demo',
            'password' => Hash::make('password'),
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
        ]);

        if (! $user->tenant_id) {
            $user->tenant_id = $tenant->id;
            $user->save();
        }

        $role = Role::firstOrCreate(['name' => 'merchant-user', 'guard_name' => 'web']);
        $user->syncRoles([$role]);

        if (MarketSpace::query()->where('tenant_id', $tenant->id)->count() === 0) {
            MarketSpace::create([
                'market_id' => $market->id,
                'number' => 'А-12',
                'area_sqm' => 12.5,
                'status' => 'leased',
                'tenant_id' => $tenant->id,
            ]);

            MarketSpace::create([
                'market_id' => $market->id,
                'number' => 'А-13',
                'area_sqm' => 10.0,
                'status' => 'leased',
                'tenant_id' => $tenant->id,
            ]);
        }

        TenantContract::query()->firstOrCreate([
            'tenant_id' => $tenant->id,
        ], [
            'market_id' => $market->id,
            'number' => 'Д-2024/15',
            'status' => 'active',
            'starts_at' => Carbon::parse('2024-01-01'),
            'ends_at' => Carbon::parse('2024-12-31'),
            'monthly_rent' => 55000,
            'currency' => 'RUB',
            'is_active' => true,
        ]);

        $period = Carbon::now()->startOfMonth();

        if (TenantAccrual::query()->where('tenant_id', $tenant->id)->count() === 0) {
            TenantAccrual::create([
                'market_id' => $market->id,
                'tenant_id' => $tenant->id,
                'period' => $period,
                'source_place_name' => 'Торговое место А-12',
                'rent_amount' => 35000,
                'utilities_amount' => 5000,
                'management_fee' => 3000,
                'total_with_vat' => 43000,
                'status' => 'imported',
            ]);

            TenantAccrual::create([
                'market_id' => $market->id,
                'tenant_id' => $tenant->id,
                'period' => $period,
                'source_place_name' => 'Торговое место А-13',
                'rent_amount' => 28000,
                'utilities_amount' => 4000,
                'management_fee' => 2500,
                'total_with_vat' => 34500,
                'status' => 'imported',
            ]);
        }

        if (TenantDocument::query()->where('tenant_id', $tenant->id)->count() === 0) {
            Storage::disk('public')->put('tenant-documents/contract.pdf', 'Demo contract');
            Storage::disk('public')->put('tenant-documents/act.pdf', 'Demo act');
            Storage::disk('public')->put('tenant-documents/other.pdf', 'Demo document');

            TenantDocument::create([
                'tenant_id' => $tenant->id,
                'type' => 'Договор',
                'title' => 'Договор аренды № Д-2024/15',
                'document_date' => Carbon::parse('2024-01-01'),
                'file_path' => 'tenant-documents/contract.pdf',
            ]);
            TenantDocument::create([
                'tenant_id' => $tenant->id,
                'type' => 'Акт',
                'title' => 'Акт сверки за январь',
                'document_date' => Carbon::parse('2024-02-01'),
                'file_path' => 'tenant-documents/act.pdf',
            ]);
            TenantDocument::create([
                'tenant_id' => $tenant->id,
                'type' => 'Прочее',
                'title' => 'Памятка арендатора',
                'document_date' => Carbon::parse('2024-02-10'),
                'file_path' => 'tenant-documents/other.pdf',
            ]);
        }

        if (Ticket::query()->where('tenant_id', $tenant->id)->count() === 0) {
            $ticket = Ticket::create([
                'market_id' => $market->id,
                'tenant_id' => $tenant->id,
                'subject' => 'Не работает подсветка витрины',
                'description' => 'Просьба заменить лампу над витриной.',
                'category' => 'repair',
                'priority' => 'normal',
                'status' => 'in_progress',
            ]);

            TicketComment::create([
                'ticket_id' => $ticket->id,
                'user_id' => $user->id,
                'body' => 'Подскажите, когда можно ожидать мастера?',
            ]);

            TicketComment::create([
                'ticket_id' => $ticket->id,
                'user_id' => $user->id,
                'body' => 'Сегодня после обеда администратор свяжется с вами.',
            ]);
        }

        TenantShowcase::query()->firstOrCreate([
            'tenant_id' => $tenant->id,
        ], [
            'title' => 'ЭкоЯр — свежие овощи и фермерские продукты',
            'description' => 'Домашние овощи, зелень и сезонные ягоды. Доставка по району.',
            'phone' => '+7 900 000-00-00',
            'telegram' => 'https://t.me/ekoyar',
            'website' => 'https://example.com',
            'photos' => $this->seedShowcasePhotos(),
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function seedShowcasePhotos(): array
    {
        $base64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+qXeoAAAAASUVORK5CYII=';
        $binary = base64_decode($base64);

        $paths = [];

        for ($i = 1; $i <= 5; $i += 1) {
            $path = "tenant-showcases/demo-{$i}.png";
            Storage::disk('public')->put($path, $binary);
            $paths[] = $path;
        }

        return $paths;
    }
}
