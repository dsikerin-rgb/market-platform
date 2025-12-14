<?php

namespace App\Filament\Resources\TenantResource\RelationManagers;

use App\Models\MarketSpace;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ContractsRelationManager extends RelationManager
{
    protected static string $relationship = 'contracts';

    protected static ?string $title = 'Договоры';

    protected static ?string $recordTitleAttribute = 'number';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\Hidden::make('market_id')
                ->default(fn (RelationManager $livewire) => $livewire->getOwnerRecord()->market_id)
                ->dehydrated(true),

            Forms\Components\Hidden::make('tenant_id')
                ->default(fn (RelationManager $livewire) => $livewire->getOwnerRecord()->id)
                ->dehydrated(true),

            Forms\Components\Select::make('market_space_id')
                ->label('Торговое место')
                ->options(function (RelationManager $livewire) {
                    $marketId = $livewire->getOwnerRecord()->market_id;

                    return MarketSpace::query()
                        ->where('market_id', $marketId)
                        ->orderBy('number')
                        ->pluck('number', 'id')
                        ->toArray();
                })
                ->searchable()
                ->preload()
                ->nullable(),

            Forms\Components\TextInput::make('number')
                ->label('Номер договора')
                ->required()
                ->maxLength(50),

            Forms\Components\Select::make('status')
                ->label('Статус')
                ->options([
                    'draft' => 'Черновик',
                    'active' => 'Активен',
                    'paused' => 'Приостановлен',
                    'terminated' => 'Расторгнут',
                    'archived' => 'Архив',
                ])
                ->default('draft'),

            Forms\Components\DatePicker::make('starts_at')
                ->label('Дата начала')
                ->required(),

            Forms\Components\DatePicker::make('ends_at')
                ->label('Дата окончания')
                ->nullable(),

            Forms\Components\DatePicker::make('signed_at')
                ->label('Дата подписания')
                ->nullable(),

            Forms\Components\TextInput::make('monthly_rent')
                ->label('Арендная ставка в месяц')
                ->numeric()
                ->step('0.01')
                ->nullable(),

            Forms\Components\Select::make('currency')
                ->label('Валюта')
                ->options([
                    'RUB' => '₽',
                    'USD' => '$',
                    'EUR' => '€',
                ])
                ->nullable(),

            Forms\Components\Toggle::make('is_active')
                ->label('Активен')
                ->default(true),

            Forms\Components\Textarea::make('notes')
                ->label('Примечания')
                ->columnSpanFull()
                ->nullable(),
        ]);
    }

    protected function canManage(): bool
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return false;
        }

        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        // market-admin управляет только договорaми арендаторов своего рынка
        return $user->hasRole('market-admin')
            && (int) $user->market_id === (int) $this->getOwnerRecord()->market_id;
    }

    public function table(Table $table): Table
    {
        $user = Filament::auth()->user();

        /**
         * Header action: "Добавить договор"
         * (в некоторых версиях это Tables\Actions\CreateAction, в некоторых — Actions\CreateAction)
         */
        $headerActions = [];

        if (class_exists(\Filament\Tables\Actions\CreateAction::class)) {
            $headerActions[] = \Filament\Tables\Actions\CreateAction::make()
                ->label('Добавить договор')
                ->visible(fn () => $this->canManage());
        } elseif (class_exists(\Filament\Actions\CreateAction::class)) {
            $headerActions[] = \Filament\Actions\CreateAction::make()
                ->label('Добавить договор')
                ->visible(fn () => $this->canManage());
        }

        // Row actions (совместимо с разными версиями Filament)
        $rowActions = [];

        if (class_exists(\Filament\Actions\EditAction::class)) {
            $rowActions[] = \Filament\Actions\EditAction::make()
                ->label('Редактировать')
                ->visible(fn () => $this->canManage());
        } elseif (class_exists(\Filament\Tables\Actions\EditAction::class)) {
            $rowActions[] = \Filament\Tables\Actions\EditAction::make()
                ->label('Редактировать')
                ->visible(fn () => $this->canManage());
        }

        if (class_exists(\Filament\Actions\DeleteAction::class)) {
            $rowActions[] = \Filament\Actions\DeleteAction::make()
                ->label('Удалить')
                ->visible(fn () => $this->canManage());
        } elseif (class_exists(\Filament\Tables\Actions\DeleteAction::class)) {
            $rowActions[] = \Filament\Tables\Actions\DeleteAction::make()
                ->label('Удалить')
                ->visible(fn () => $this->canManage());
        }

        // Bulk actions (тоже совместимо)
        $bulkActions = [];

        $deleteBulk = null;
        if (class_exists(\Filament\Tables\Actions\DeleteBulkAction::class)) {
            $deleteBulk = \Filament\Tables\Actions\DeleteBulkAction::make()
                ->label('Удалить выбранные');
        } elseif (class_exists(\Filament\Actions\DeleteBulkAction::class)) {
            $deleteBulk = \Filament\Actions\DeleteBulkAction::make()
                ->label('Удалить выбранные');
        }

        if ($deleteBulk) {
            $deleteBulk->visible(fn () => $this->canManage());

            if (class_exists(\Filament\Tables\Actions\BulkActionGroup::class)) {
                $bulkActions[] = \Filament\Tables\Actions\BulkActionGroup::make([$deleteBulk]);
            } else {
                $bulkActions[] = $deleteBulk;
            }
        }

        $table = $table->columns([
            TextColumn::make('market.name')
                ->label('Рынок')
                ->visible(fn () => (bool) $user && method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()),

            TextColumn::make('number')
                ->label('Номер договора')
                ->sortable()
                ->searchable(),

            TextColumn::make('marketSpace.number')
                ->label('Торговое место')
                ->sortable()
                ->searchable(),

            TextColumn::make('status')
                ->label('Статус')
                ->formatStateUsing(fn (?string $state) => match ($state) {
                    'draft' => 'Черновик',
                    'active' => 'Активен',
                    'paused' => 'Приостановлен',
                    'terminated' => 'Расторгнут',
                    'archived' => 'Архив',
                    default => $state,
                }),

            TextColumn::make('starts_at')
                ->label('Начало')
                ->date(),

            TextColumn::make('ends_at')
                ->label('Окончание')
                ->date(),

            TextColumn::make('monthly_rent')
                ->label('Аренда в месяц')
                ->numeric(decimalPlaces: 2),

            IconColumn::make('is_active')
                ->label('Активен')
                ->boolean(),
        ]);

        if (! empty($headerActions)) {
            $table = $table->headerActions($headerActions);
        }

        if (! empty($rowActions)) {
            $table = $table->actions($rowActions);
        }

        if (! empty($bulkActions)) {
            $table = $table->bulkActions($bulkActions);
        }

        return $table;
    }

    /**
     * В твоей версии Filament parent::getTableQuery() может быть null.
     * Поэтому берём query из relationship.
     */
    public function getTableQuery(): Builder
    {
        $user = Filament::auth()->user();

        /** @var Builder $query */
        $query = $this->getRelationship()->getQuery();

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return $query;
        }

        if ($user->market_id) {
            return $query->where('market_id', $user->market_id);
        }

        return $query->whereRaw('1 = 0');
    }
}
