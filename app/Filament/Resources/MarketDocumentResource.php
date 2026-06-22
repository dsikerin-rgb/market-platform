<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\MarketDocumentResource\Pages;
use App\Models\MarketDocument;
use App\Models\MarketDocumentFolder;
use App\Models\MarketSpace;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\TenantContract;
use App\Models\TenantRequest;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema as DbSchema;
use Illuminate\Support\Str;

class MarketDocumentResource extends BaseResource
{
    protected static ?string $model = MarketDocument::class;

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?string $navigationLabel = 'Документы';
    protected static ?string $modelLabel = 'документ';
    protected static ?string $pluralModelLabel = 'Документы';
    protected static \UnitEnum|string|null $navigationGroup = null;
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-folder';
    protected static ?int $navigationSort = 75;

    public static function form(Schema $schema): Schema
    {
        $user = Filament::auth()->user();
        $isSuperAdmin = (bool) $user && $user->isSuperAdmin();
        $selectedMarketId = static::selectedMarketIdFromSession();

        $marketField = $isSuperAdmin && ! $selectedMarketId
            ? Forms\Components\Select::make('market_id')
                ->label('Рынок')
                ->relationship('market', 'name')
                ->required()
                ->searchable()
                ->preload()
                ->reactive()
            : Forms\Components\Hidden::make('market_id')
                ->default(fn (?MarketDocument $record) => $record?->market_id ?: ($selectedMarketId ?: $user?->market_id))
                ->dehydrated(true);

        return $schema->components([
            Section::make('Размещение')
                ->description('Выберите, будет файл общим для рынка или личным документом сотрудника.')
                ->schema([
                    $marketField,

                    Forms\Components\Select::make('visibility')
                        ->label('Раздел')
                        ->options(MarketDocument::visibilityOptions())
                        ->default(MarketDocument::VISIBILITY_PERSONAL)
                        ->required()
                        ->reactive(),

                    Forms\Components\Select::make('owner_user_id')
                        ->label('Владелец личного раздела')
                        ->options(fn (Get $get): array => static::ownerOptions($get('market_id') ? (int) $get('market_id') : null))
                        ->default(fn (?MarketDocument $record) => $record?->owner_user_id ?: $user?->id)
                        ->searchable()
                        ->preload()
                        ->required(fn (Get $get): bool => $get('visibility') === MarketDocument::VISIBILITY_PERSONAL)
                        ->visible(fn (Get $get): bool => $get('visibility') === MarketDocument::VISIBILITY_PERSONAL)
                        ->disabled(fn (): bool => ! static::canManageOtherOwners())
                        ->dehydrated(true),

                    Forms\Components\Select::make('category')
                        ->label('Категория')
                        ->options(MarketDocument::categoryOptions())
                        ->default(MarketDocument::CATEGORY_GENERAL)
                        ->required(),

                    Forms\Components\Select::make('folder_id')
                        ->label('Папка')
                        ->options(fn (Get $get): array => static::folderOptions(
                            $get('market_id') ? (int) $get('market_id') : null,
                            (string) ($get('visibility') ?: MarketDocument::VISIBILITY_PERSONAL),
                            $get('owner_user_id') ? (int) $get('owner_user_id') : null,
                        ))
                        ->placeholder('Без папки')
                        ->searchable()
                        ->preload(),
                ])
                ->columns(2),

            Section::make('Файл')
                ->description('Документ будет сохранён в хранилище, выбранном для общего диска.')
                ->schema([
                    Forms\Components\FileUpload::make('file_path')
                        ->label('Файл')
                        ->disk(MarketDocument::storageDisk())
                        ->directory(fn (Get $get): string => MarketDocument::storageDirectory(
                            $get('market_id') ? (int) $get('market_id') : null,
                            $get('owner_user_id') ? (int) $get('owner_user_id') : (Filament::auth()->id() ? (int) Filament::auth()->id() : null),
                            (string) ($get('visibility') ?: MarketDocument::VISIBILITY_PERSONAL),
                            $get('folder_id') ? (int) $get('folder_id') : null,
                        ))
                        ->storeFileNamesIn('original_name')
                        ->downloadable()
                        ->openable()
                        ->required()
                        ->columnSpanFull(),

                    Forms\Components\TextInput::make('title')
                        ->label('Название')
                        ->placeholder('Например: Регламент работы рынка')
                        ->maxLength(255)
                        ->columnSpanFull(),

                    Forms\Components\Textarea::make('description')
                        ->label('Описание')
                        ->rows(3)
                        ->columnSpanFull(),
                ])
                ->columns(2),

            Section::make('Связь с сервисом')
                ->description('Можно указать, к чему относится документ: арендатору, договору, месту, задаче или обращению.')
                ->schema([
                    Forms\Components\Select::make('related_type')
                        ->label('Тип связи')
                        ->options(static::relatedTypeOptions())
                        ->placeholder('Не связывать')
                        ->reactive(),

                    Forms\Components\Select::make('related_id')
                        ->label('Ресурс')
                        ->options(fn (Get $get): array => static::relatedOptions(
                            $get('related_type') ? (string) $get('related_type') : null,
                            $get('market_id') ? (int) $get('market_id') : null,
                        ))
                        ->placeholder('Выберите ресурс')
                        ->searchable()
                        ->preload()
                        ->visible(fn (Get $get): bool => filled($get('related_type'))),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        $user = Filament::auth()->user();

        $table = $table
            ->columns([
                TextColumn::make('title')
                    ->label('Документ')
                    ->description(fn (MarketDocument $record): string => $record->resolvedFileName())
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                TextColumn::make('visibility')
                    ->label('Раздел')
                    ->formatStateUsing(fn (?string $state, MarketDocument $record): string => $record->visibilityLabel())
                    ->badge()
                    ->color(fn (?string $state): string => $state === MarketDocument::VISIBILITY_SHARED ? 'primary' : 'gray')
                    ->sortable(),

                TextColumn::make('category')
                    ->label('Категория')
                    ->formatStateUsing(fn (?string $state, MarketDocument $record): string => $record->categoryLabel())
                    ->badge()
                    ->color('gray')
                    ->sortable(),

                TextColumn::make('folder.name')
                    ->label('Папка')
                    ->formatStateUsing(fn (?string $state, MarketDocument $record): string => $record->folder?->displayName() ?? 'Без папки')
                    ->placeholder('Без папки')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('related_id')
                    ->label('Связано с')
                    ->formatStateUsing(fn ($state, MarketDocument $record): string => $record->relatedLabel())
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('owner.name')
                    ->label('Личный раздел')
                    ->placeholder('Общий раздел')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('market.name')
                    ->label('Рынок')
                    ->visible(fn (): bool => (bool) $user && $user->isSuperAdmin())
                    ->sortable()
                    ->searchable(),

                TextColumn::make('uploadedBy.name')
                    ->label('Добавил')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable()
                    ->searchable(),

                TextColumn::make('file_size')
                    ->label('Размер')
                    ->formatStateUsing(fn ($state, MarketDocument $record): string => $record->fileSizeLabel())
                    ->alignEnd(),

                TextColumn::make('created_at')
                    ->label('Добавлен')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),

                TextColumn::make('archived_at')
                    ->label('Архив')
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('Активен')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->label('Категория')
                    ->options(MarketDocument::categoryOptions()),

                SelectFilter::make('folder_id')
                    ->label('Папка')
                    ->options(fn (): array => static::folderOptions(null, null, null)),

                TernaryFilter::make('archived')
                    ->label('Архив')
                    ->placeholder('Все')
                    ->trueLabel('Только архив')
                    ->falseLabel('Только активные')
                    ->default(false)
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('archived_at'),
                        false: fn (Builder $query): Builder => $query->whereNull('archived_at'),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->recordUrl(fn (MarketDocument $record): ?string => static::canEdit($record)
                ? static::getUrl('edit', ['record' => $record])
                : null)
            ->defaultSort('created_at', 'desc')
            ->emptyStateIcon('heroicon-o-folder-open')
            ->emptyStateHeading('Документов пока нет')
            ->emptyStateDescription('Загрузите первый файл в общий раздел рынка или в личный раздел сотрудника.');

        $actions = static::tableActions();

        if ($actions !== []) {
            $table = $table->actions($actions);
        }

        return $table;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMarketDocuments::route('/'),
            'create' => Pages\CreateMarketDocument::route('/create'),
            'edit' => Pages\EditMarketDocument::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->with(['market', 'owner', 'uploadedBy', 'folder.parent', 'related']);

        $user = Filament::auth()->user();

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->isSuperAdmin()) {
            $selectedMarketId = static::selectedMarketIdFromSession();

            return filled($selectedMarketId)
                ? $query->where('market_id', (int) $selectedMarketId)
                : $query;
        }

        return $query->visibleFor($user);
    }

    public static function canViewAny(): bool
    {
        $user = Filament::auth()->user();

        return (bool) $user && ($user->isSuperAdmin() || (bool) $user->market_id);
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit($record): bool
    {
        $user = Filament::auth()->user();

        if (! $user || ! ($record instanceof MarketDocument)) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        if (! $user->market_id || (int) $record->market_id !== (int) $user->market_id) {
            return false;
        }

        return $record->visibility === MarketDocument::VISIBILITY_SHARED
            || (int) $record->owner_user_id === (int) $user->id
            || $user->isMarketAdmin();
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canManageOtherOwners(): bool
    {
        $user = Filament::auth()->user();

        return (bool) $user && ($user->isSuperAdmin() || $user->isMarketAdmin());
    }

    /**
     * @return array<int, string>
     */
    public static function ownerOptions(?int $marketId): array
    {
        $user = Filament::auth()->user();

        return User::query()
            ->when($marketId, fn (Builder $query): Builder => $query->where('market_id', $marketId))
            ->when(! $marketId && $user?->market_id, fn (Builder $query): Builder => $query->where('market_id', (int) $user->market_id))
            ->whereDoesntHave('roles', fn (Builder $query): Builder => $query->whereIn('name', ['merchant', 'merchant-user', 'buyer', 'tenant']))
            ->orderBy('name')
            ->limit(200)
            ->pluck('name', 'id')
            ->map(fn (?string $name, int|string $id): string => trim((string) $name) !== '' ? (string) $name : "Сотрудник #{$id}")
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public static function folderOptions(?int $marketId, ?string $visibility, ?int $ownerUserId): array
    {
        if (! DbSchema::hasTable('market_document_folders')) {
            return [];
        }

        $user = Filament::auth()->user();

        return MarketDocumentFolder::query()
            ->visibleFor($user)
            ->whereNull('archived_at')
            ->when($marketId, fn (Builder $query): Builder => $query->where('market_id', $marketId))
            ->when($visibility, fn (Builder $query): Builder => $query->where('visibility', MarketDocument::normalizeVisibility($visibility)))
            ->when(
                $visibility === MarketDocument::VISIBILITY_PERSONAL && $ownerUserId,
                fn (Builder $query): Builder => $query->where('owner_user_id', $ownerUserId)
            )
            ->with('parent')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->limit(200)
            ->get()
            ->mapWithKeys(fn (MarketDocumentFolder $folder): array => [$folder->id => $folder->displayName()])
            ->all();
    }

    /**
     * @return array<class-string, string>
     */
    protected static function relatedTypeOptions(): array
    {
        return [
            Tenant::class => 'Арендатор',
            TenantContract::class => 'Договор',
            MarketSpace::class => 'Торговое место',
            Task::class => 'Задача',
            TenantRequest::class => 'Обращение арендатора',
        ];
    }

    /**
     * @return array<int, string>
     */
    protected static function relatedOptions(?string $type, ?int $marketId): array
    {
        if (! $type || ! array_key_exists($type, static::relatedTypeOptions())) {
            return [];
        }

        $user = Filament::auth()->user();
        $resolvedMarketId = $marketId ?: ($user?->market_id ? (int) $user->market_id : null);

        return match ($type) {
            Tenant::class => Tenant::query()
                ->when($resolvedMarketId, fn (Builder $query): Builder => $query->where('market_id', $resolvedMarketId))
                ->orderBy('name')
                ->limit(100)
                ->get()
                ->mapWithKeys(fn (Tenant $tenant): array => [$tenant->id => $tenant->display_name])
                ->all(),
            TenantContract::class => TenantContract::query()
                ->with('tenant')
                ->when($resolvedMarketId, fn (Builder $query): Builder => $query->where('market_id', $resolvedMarketId))
                ->latest('id')
                ->limit(100)
                ->get()
                ->mapWithKeys(fn (TenantContract $contract): array => [$contract->id => static::contractOptionLabel($contract)])
                ->all(),
            MarketSpace::class => MarketSpace::query()
                ->when($resolvedMarketId, fn (Builder $query): Builder => $query->where('market_id', $resolvedMarketId))
                ->orderBy('number')
                ->limit(100)
                ->get()
                ->mapWithKeys(fn (MarketSpace $space): array => [$space->id => static::spaceOptionLabel($space)])
                ->all(),
            Task::class => Task::query()
                ->when($resolvedMarketId, fn (Builder $query): Builder => $query->where('market_id', $resolvedMarketId))
                ->latest('id')
                ->limit(100)
                ->get()
                ->mapWithKeys(fn (Task $task): array => [$task->id => static::taskOptionLabel($task)])
                ->all(),
            TenantRequest::class => TenantRequest::query()
                ->when($resolvedMarketId, fn (Builder $query): Builder => $query->where('market_id', $resolvedMarketId))
                ->latest('id')
                ->limit(100)
                ->get()
                ->mapWithKeys(fn (TenantRequest $request): array => [$request->id => static::requestOptionLabel($request)])
                ->all(),
            default => [],
        };
    }

    protected static function contractOptionLabel(TenantContract $contract): string
    {
        $number = trim((string) ($contract->number ?? ''));
        $tenant = trim((string) ($contract->tenant?->display_name ?? ''));

        return trim('Договор ' . ($number !== '' ? $number : ('#' . $contract->id)) . ($tenant !== '' ? " · {$tenant}" : ''));
    }

    protected static function spaceOptionLabel(MarketSpace $space): string
    {
        $number = trim((string) ($space->number ?: $space->code ?: ''));
        $name = trim((string) ($space->display_name ?? ''));

        return trim('Место ' . ($number !== '' ? $number : ('#' . $space->id)) . ($name !== '' ? " · {$name}" : ''));
    }

    protected static function taskOptionLabel(Task $task): string
    {
        $title = trim((string) ($task->title ?? ''));

        return Str::limit($title !== '' ? $title : ('Задача #' . $task->id), 90);
    }

    protected static function requestOptionLabel(TenantRequest $request): string
    {
        $subject = trim((string) ($request->subject ?? ''));
        $ticket = trim((string) ($request->ticket_id ?? ''));
        $base = $subject !== '' ? $subject : ('Обращение #' . $request->id);

        return Str::limit($ticket !== '' ? "{$base} · {$ticket}" : $base, 90);
    }

    /**
     * @return array<int, mixed>
     */
    protected static function tableActions(): array
    {
        $actions = [];

        $download = class_exists(\Filament\Actions\Action::class)
            ? \Filament\Actions\Action::make('download')
            : (class_exists(\Filament\Tables\Actions\Action::class) ? \Filament\Tables\Actions\Action::make('download') : null);

        if ($download) {
            $download
                ->label('Скачать')
                ->icon('heroicon-o-arrow-down-tray')
                ->url(fn (MarketDocument $record): ?string => $record->temporaryDownloadUrl())
                ->openUrlInNewTab()
                ->visible(fn (MarketDocument $record): bool => filled($record->file_path));

            $actions[] = $download;
        }

        if (class_exists(\Filament\Actions\EditAction::class)) {
            $actions[] = \Filament\Actions\EditAction::make()->label('Редактировать');
        } elseif (class_exists(\Filament\Tables\Actions\EditAction::class)) {
            $actions[] = \Filament\Tables\Actions\EditAction::make()->label('Редактировать');
        }

        $archive = class_exists(\Filament\Actions\Action::class)
            ? \Filament\Actions\Action::make('archive')
            : (class_exists(\Filament\Tables\Actions\Action::class) ? \Filament\Tables\Actions\Action::make('archive') : null);

        if ($archive) {
            $archive
                ->label('В архив')
                ->icon('heroicon-o-archive-box')
                ->color('gray')
                ->requiresConfirmation()
                ->visible(fn (MarketDocument $record): bool => blank($record->archived_at))
                ->action(fn (MarketDocument $record): bool => $record->update(['archived_at' => now()]));

            $actions[] = $archive;
        }

        $restore = class_exists(\Filament\Actions\Action::class)
            ? \Filament\Actions\Action::make('restore')
            : (class_exists(\Filament\Tables\Actions\Action::class) ? \Filament\Tables\Actions\Action::make('restore') : null);

        if ($restore) {
            $restore
                ->label('Вернуть')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('primary')
                ->visible(fn (MarketDocument $record): bool => filled($record->archived_at))
                ->action(fn (MarketDocument $record): bool => $record->update(['archived_at' => null]));

            $actions[] = $restore;
        }

        return $actions;
    }

    protected static function selectedMarketIdFromSession(): ?int
    {
        $panelId = Filament::getCurrentPanel()?->getId() ?? 'admin';

        $value =
            session("filament.{$panelId}.selected_market_id")
            ?? session("filament_{$panelId}_market_id")
            ?? session("filament.{$panelId}.market_id")
            ?? session('filament.admin.selected_market_id')
            ?? session('filament.admin.market_id')
            ?? session('dashboard_market_id')
            ?? session('selected_market_id');

        return filled($value) ? (int) $value : null;
    }
}
