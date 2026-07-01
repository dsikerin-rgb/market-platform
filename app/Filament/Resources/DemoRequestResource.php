<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\DemoRequestResource\Pages;
use App\Models\DemoRequest;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class DemoRequestResource extends BaseResource
{
    protected static ?string $model = DemoRequest::class;

    protected static ?string $slug = 'demo-requests';

    protected static ?string $recordTitleAttribute = 'organization';

    protected static ?string $navigationLabel = 'Заявки на демо';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-inbox-arrow-down';

    protected static \UnitEnum|string|null $navigationGroup = null;

    protected static ?int $navigationSort = 156;

    protected static ?string $modelLabel = 'Заявка на демо';

    protected static ?string $pluralModelLabel = 'Заявки на демо';

    public static function getGloballySearchableAttributes(): array
    {
        return [
            'name',
            'organization',
            'email',
            'phone',
            'city',
        ];
    }

    public static function form(Schema $schema): Schema
    {
        $statusFields = [
            Forms\Components\Select::make('status')
                ->label('Статус')
                ->options(DemoRequest::statusOptions())
                ->required(),

            Forms\Components\DateTimePicker::make('processed_at')
                ->label('Обработано')
                ->seconds(false),
        ];

        $readOnlyFields = [
            Forms\Components\TextInput::make('request_type')
                ->label('Тип запроса')
                ->formatStateUsing(static fn (?string $state): string => DemoRequest::typeLabel((string) $state))
                ->disabled()
                ->dehydrated(false),

            Forms\Components\TextInput::make('name')
                ->label('Имя')
                ->disabled()
                ->dehydrated(false),

            Forms\Components\TextInput::make('organization')
                ->label('Организация')
                ->disabled()
                ->dehydrated(false),

            Forms\Components\TextInput::make('email')
                ->label('Email')
                ->disabled()
                ->dehydrated(false),

            Forms\Components\TextInput::make('phone')
                ->label('Телефон')
                ->disabled()
                ->dehydrated(false),

            Forms\Components\TextInput::make('city')
                ->label('Город')
                ->disabled()
                ->dehydrated(false),

            Forms\Components\TextInput::make('market_format')
                ->label('Формат рынка')
                ->disabled()
                ->dehydrated(false),

            Forms\Components\TextInput::make('spaces_count')
                ->label('Количество мест')
                ->disabled()
                ->dehydrated(false),

            Forms\Components\Textarea::make('message')
                ->label('Комментарий')
                ->rows(5)
                ->disabled()
                ->dehydrated(false),

            Forms\Components\TextInput::make('source')
                ->label('Источник')
                ->disabled()
                ->dehydrated(false),

            Forms\Components\DateTimePicker::make('created_at')
                ->label('Создано')
                ->seconds(false)
                ->disabled()
                ->dehydrated(false),
        ];

        if (class_exists(Forms\Components\Grid::class)) {
            return $schema->components([
                Forms\Components\Grid::make(2)->components($statusFields),
                Forms\Components\Grid::make(2)->components($readOnlyFields),
            ]);
        }

        return $schema->components([
            ...$statusFields,
            ...$readOnlyFields,
        ]);
    }

    public static function table(Table $table): Table
    {
        $table = $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Получена')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->color(static fn (?string $state): string => DemoRequest::statusColor($state))
                    ->formatStateUsing(static fn (?string $state): string => DemoRequest::statusLabel($state))
                    ->sortable(),

                TextColumn::make('request_type')
                    ->label('Тип')
                    ->badge()
                    ->formatStateUsing(static fn (?string $state): string => DemoRequest::typeLabel((string) $state))
                    ->sortable(),

                TextColumn::make('organization')
                    ->label('Организация')
                    ->searchable()
                    ->sortable()
                    ->limit(36)
                    ->tooltip(static fn (?string $state): ?string => filled($state) ? $state : null),

                TextColumn::make('name')
                    ->label('Контакт')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->copyable()
                    ->toggleable(),

                TextColumn::make('phone')
                    ->label('Телефон')
                    ->searchable()
                    ->copyable()
                    ->toggleable(),

                TextColumn::make('city')
                    ->label('Город')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('market_format')
                    ->label('Формат')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('spaces_count')
                    ->label('Мест')
                    ->numeric()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('message')
                    ->label('Комментарий')
                    ->formatStateUsing(static fn (?string $state): string => filled($state) ? Str::limit($state, 80) : '—')
                    ->tooltip(static fn (?string $state): ?string => filled($state) ? $state : null)
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('processed_at')
                    ->label('Обработано')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options(DemoRequest::statusOptions())
                    ->placeholder('Все'),

                SelectFilter::make('request_type')
                    ->label('Тип')
                    ->options([
                        DemoRequest::TYPE_DEMO => DemoRequest::typeLabel(DemoRequest::TYPE_DEMO),
                        DemoRequest::TYPE_PILOT => DemoRequest::typeLabel(DemoRequest::TYPE_PILOT),
                        DemoRequest::TYPE_FREE => DemoRequest::typeLabel(DemoRequest::TYPE_FREE),
                    ])
                    ->placeholder('Все'),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordUrl(fn (DemoRequest $record): string => static::getUrl('edit', ['record' => $record]))
            ->emptyStateHeading('Заявок пока нет')
            ->emptyStateDescription('Заявки появятся здесь после отправки формы на landing.');

        $actions = [];

        if (class_exists(\Filament\Actions\EditAction::class)) {
            $actions[] = \Filament\Actions\EditAction::make()
                ->label('Открыть');
        } elseif (class_exists(\Filament\Tables\Actions\EditAction::class)) {
            $actions[] = \Filament\Tables\Actions\EditAction::make()
                ->label('Открыть');
        }

        return $actions === [] ? $table : $table->actions($actions);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDemoRequests::route('/'),
            'edit' => Pages\EditDemoRequest::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (! static::canViewAny()) {
            return $query->whereRaw('1 = 0');
        }

        return $query;
    }

    public static function canViewAny(): bool
    {
        return static::canAccessDemoRequests();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return static::canAccessDemoRequests();
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    private static function canAccessDemoRequests(): bool
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return false;
        }

        $allowedUserIds = array_map(
            static fn (mixed $value): int => (int) $value,
            (array) config('saas_progress.access.allowed_user_ids', []),
        );
        $allowedEmails = array_map(
            static fn (mixed $value): string => mb_strtolower(trim((string) $value), 'UTF-8'),
            (array) config('saas_progress.access.allowed_user_emails', []),
        );

        $email = mb_strtolower(trim((string) ($user->email ?? '')), 'UTF-8');

        return in_array((int) $user->id, $allowedUserIds, true)
            || ($email !== '' && in_array($email, $allowedEmails, true));
    }
}
