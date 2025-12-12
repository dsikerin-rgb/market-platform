<?php

namespace App\Filament\Resources\TenantResource\RelationManagers;

use App\Models\MarketSpace;
use App\Models\TenantContract;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class RequestsRelationManager extends RelationManager
{
    protected static string $relationship = 'requests';

    protected static ?string $title = 'Обращения';

    protected static ?string $recordTitleAttribute = 'subject';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\Hidden::make('market_id')
                ->default(fn (RelationManager $livewire) => $livewire->getOwnerRecord()->market_id)
                ->dehydrated(true),

            Forms\Components\Hidden::make('tenant_id')
                ->default(fn (RelationManager $livewire) => $livewire->getOwnerRecord()->id)
                ->dehydrated(true),

            Forms\Components\Hidden::make('created_by_user_id')
                ->default(fn () => Filament::auth()->id())
                ->dehydrated(true),

            Forms\Components\TextInput::make('subject')
                ->label('Тема')
                ->required()
                ->maxLength(255),

            Forms\Components\Textarea::make('description')
                ->label('Описание обращения')
                ->required(),

            Forms\Components\Select::make('category')
                ->label('Категория')
                ->options([
                    'maintenance' => 'Обслуживание и ремонт',
                    'payment' => 'Оплата и расчёты',
                    'documents' => 'Документы и отчётность',
                    'technical' => 'Технические вопросы',
                    'other' => 'Другое',
                ])
                ->default('other'),

            Forms\Components\Select::make('priority')
                ->label('Приоритет')
                ->options([
                    'low' => 'Низкий',
                    'normal' => 'Обычный',
                    'high' => 'Высокий',
                    'urgent' => 'Критичный',
                ])
                ->default('normal'),

            Forms\Components\Select::make('status')
                ->label('Статус')
                ->options([
                    'new' => 'Новое',
                    'in_progress' => 'В работе',
                    'resolved' => 'Решено',
                    'closed' => 'Закрыто',
                ])
                ->default('new'),

            Forms\Components\Select::make('market_space_id')
                ->label('Торговое место')
                ->options(function (RelationManager $livewire) {
                    $marketId = $livewire->getOwnerRecord()->market_id;

                    return MarketSpace::where('market_id', $marketId)
                        ->orderBy('number')
                        ->pluck('number', 'id');
                })
                ->searchable()
                ->preload()
                ->nullable(),

            Forms\Components\Select::make('tenant_contract_id')
                ->label('Договор')
                ->options(function (RelationManager $livewire) {
                    $owner = $livewire->getOwnerRecord();

                    return TenantContract::where('tenant_id', $owner->id)
                        ->where('market_id', $owner->market_id)
                        ->orderBy('number')
                        ->pluck('number', 'id');
                })
                ->searchable()
                ->preload()
                ->nullable(),

            Forms\Components\Textarea::make('internal_notes')
                ->label('Внутренние комментарии')
                ->columnSpanFull()
                ->nullable(),

            Forms\Components\Toggle::make('is_active')
                ->label('Активно')
                ->default(true),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('subject')
                    ->label('Тема')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('category')
                    ->label('Категория')
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        'maintenance' => 'Обслуживание и ремонт',
                        'payment' => 'Оплата и расчёты',
                        'documents' => 'Документы и отчётность',
                        'technical' => 'Технические вопросы',
                        'other' => 'Другое',
                        default => $state,
                    }),

                TextColumn::make('priority')
                    ->label('Приоритет')
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        'low' => 'Низкий',
                        'normal' => 'Обычный',
                        'high' => 'Высокий',
                        'urgent' => 'Критичный',
                        default => $state,
                    }),

                TextColumn::make('status')
                    ->label('Статус')
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        'new' => 'Новое',
                        'in_progress' => 'В работе',
                        'resolved' => 'Решено',
                        'closed' => 'Закрыто',
                        default => $state,
                    }),

                TextColumn::make('marketSpace.number')
                    ->label('Место')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('created_at')
                    ->label('Создано')
                    ->dateTime()
                    ->sortable(),

                TextColumn::make('resolved_at')
                    ->label('Решено')
                    ->dateTime(),

                TextColumn::make('closed_at')
                    ->label('Закрыто')
                    ->dateTime(),

                IconColumn::make('is_active')
                    ->label('Активно')
                    ->boolean(),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->label('Редактировать'),

                Tables\Actions\DeleteAction::make()
                    ->label('Удалить'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->label('Удалить выбранные'),
                ]),
            ]);
    }

    public function getTableQuery(): Builder
    {
        $query = parent::getTableQuery();
        $user = Filament::auth()->user();

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->isSuperAdmin()) {
            return $query;
        }

        if ($user->market_id) {
            return $query->where('market_id', $user->market_id);
        }

        return $query->whereRaw('1 = 0');
    }
}
