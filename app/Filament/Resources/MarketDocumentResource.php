<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\MarketDocumentResource\Pages;
use App\Models\MarketDocument;
use App\Models\MarketDocumentFolder;
use App\Models\MarketDocumentShare;
use App\Models\MarketSpace;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\TenantContract;
use App\Models\TenantRequest;
use App\Models\User;
use App\Notifications\MarketDocumentSharedNotification;
use App\Support\StaffConversationService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Schema as DbSchema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ZipArchive;

class MarketDocumentResource extends BaseResource
{
    public const TAB_TRASH = 'trash';

    protected static ?string $model = MarketDocument::class;

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?string $navigationLabel = 'Диск';
    protected static ?string $modelLabel = 'документ';
    protected static ?string $pluralModelLabel = 'Диск';
    protected static \UnitEnum|string|null $navigationGroup = null;
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-folder';
    protected static ?int $navigationSort = 75;

    public static function getNavigationLabel(): string
    {
        return 'Диск';
    }

    public static function form(Schema $schema): Schema
    {
        $user = Filament::auth()->user();
        $isSuperAdmin = (bool) $user && $user->isSuperAdmin();
        $selectedMarketId = static::selectedMarketIdFromSession();
        $selectedFolder = static::selectedFolderFromRequest();

        $marketField = $isSuperAdmin && ! $selectedMarketId
            ? Forms\Components\Select::make('market_id')
                ->label('Рынок')
                ->relationship('market', 'name')
                ->default(fn (?MarketDocument $record) => $record?->market_id ?: $selectedFolder?->market_id)
                ->required()
                ->searchable()
                ->preload()
                ->reactive()
            : Forms\Components\Hidden::make('market_id')
                ->default(fn (?MarketDocument $record) => $record?->market_id ?: ($selectedFolder?->market_id ?: ($selectedMarketId ?: $user?->market_id)))
                ->dehydrated(true);

        return $schema->components([
            Section::make('Размещение')
                ->description('Выберите, будет файл общим для рынка или личным документом сотрудника.')
                ->schema([
                    $marketField,

                    Forms\Components\Select::make('visibility')
                        ->label('Раздел')
                        ->options(MarketDocument::visibilityOptions())
                        ->default(fn (?MarketDocument $record) => $record?->visibility ?: ($selectedFolder?->visibility ?: MarketDocument::VISIBILITY_PERSONAL))
                        ->required()
                        ->reactive(),

                    Forms\Components\Select::make('owner_user_id')
                        ->label('Владелец личного раздела')
                        ->options(fn (Get $get): array => static::ownerOptions($get('market_id') ? (int) $get('market_id') : null))
                        ->default(fn (?MarketDocument $record) => $record?->owner_user_id ?: ($selectedFolder?->owner_user_id ?: $user?->id))
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
                        ->default(fn (?MarketDocument $record) => $record?->folder_id ?: $selectedFolder?->id)
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
                    ->formatStateUsing(fn ($state, MarketDocument $record): string => static::documentTitleLabel($record))
                    ->view('filament.resources.market-documents.columns.document-title')
                    ->searchable()
                    ->sortable()
                    ->grow()
                    ->wrap(),

                TextColumn::make('uploadedBy.name')
                    ->label('Добавил')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable()
                    ->searchable(),

                TextColumn::make('file_size')
                    ->label('Размер')
                    ->formatStateUsing(fn ($state, MarketDocument $record): string => $record->fileSizeLabel())
                    ->alignEnd()
                    ->width('86px'),

                TextColumn::make('created_at')
                    ->label('Добавлен')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->width('132px'),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateIcon('heroicon-o-folder-open')
            ->emptyStateHeading('Документов пока нет')
            ->emptyStateDescription('Загрузите первый файл в общий раздел рынка или в личный раздел сотрудника.');

        $actions = static::tableActions();

        if ($actions !== []) {
            $table = $table->actions($actions);
        }

        $toolbarActions = static::tableToolbarActions();

        if ($toolbarActions !== []) {
            $table = $table->toolbarActions($toolbarActions);
        }

        return $table;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMarketDocuments::route('/'),
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
        return static::canUseDocuments();
    }

    public static function canCreate(): bool
    {
        return static::canUseDocuments();
    }

    public static function canUseDocuments(): bool
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        return (int) $user->market_id > 0;
    }

    public static function canEdit($record): bool
    {
        return static::canManageDocument($record);
    }

    public static function canManageDocument($record): bool
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

        if ($user->isMarketAdmin()) {
            return true;
        }

        if ($record->visibility === MarketDocument::VISIBILITY_PERSONAL) {
            return (int) $record->owner_user_id === (int) $user->id;
        }

        if ($record->visibility === MarketDocument::VISIBILITY_SHARED) {
            return (int) $record->uploaded_by_user_id > 0
                && (int) $record->uploaded_by_user_id === (int) $user->id;
        }

        return false;
    }

    public static function canShareDocument($record): bool
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

    public static function canBulkManageDocuments(mixed $livewire = null): bool
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return false;
        }

        if ($user->isSuperAdmin() || $user->isMarketAdmin()) {
            return true;
        }

        if (! $user->market_id) {
            return false;
        }

        return static::bulkDocumentContextTab($livewire) === MarketDocument::VISIBILITY_PERSONAL;
    }

    public static function canBulkManageTrash(mixed $livewire = null): bool
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return false;
        }

        if ($user->isSuperAdmin() || $user->isMarketAdmin()) {
            return static::isTrashContext($livewire);
        }

        return (int) $user->market_id > 0 && static::isTrashContext($livewire);
    }

    public static function isTrashContext(mixed $livewire = null): bool
    {
        return static::bulkDocumentContextTab($livewire) === self::TAB_TRASH;
    }

    private static function bulkDocumentContextTab(mixed $livewire): ?string
    {
        if (is_object($livewire) && property_exists($livewire, 'activeTab')) {
            $activeTab = $livewire->activeTab;

            return is_string($activeTab) && $activeTab !== '' ? $activeTab : null;
        }

        $queryTab = request()->query('tab');

        return is_string($queryTab) && $queryTab !== '' ? $queryTab : null;
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

    public static function documentTitleLabel(MarketDocument $record): string
    {
        $title = trim((string) $record->title);
        $fileName = $record->resolvedFileName();

        return $title !== '' ? $title : $fileName;
    }

    /**
     * @return array{label:string,kind:string,mark:string,background:string,foreground:string}
     */
    public static function documentTypeMetaForRecord(MarketDocument $record): array
    {
        return static::documentTypeMeta(static::documentExtension($record), (string) $record->mime_type);
    }

    protected static function documentExtension(MarketDocument $record): string
    {
        $name = trim($record->resolvedFileName());
        $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));

        if ($extension !== '') {
            return $extension;
        }

        $mime = strtolower(trim((string) $record->mime_type));

        return match (true) {
            str_contains($mime, 'pdf') => 'pdf',
            str_contains($mime, 'word') || str_contains($mime, 'document') => 'doc',
            str_contains($mime, 'spreadsheet') || str_contains($mime, 'excel') => 'xls',
            str_contains($mime, 'presentation') || str_contains($mime, 'powerpoint') => 'ppt',
            str_starts_with($mime, 'image/') => 'img',
            str_starts_with($mime, 'text/') => 'txt',
            default => 'file',
        };
    }

    /**
     * @return array{label:string,kind:string,mark:string,background:string,foreground:string}
     */
    protected static function documentTypeMeta(string $extension, string $mime): array
    {
        $extension = strtolower(trim($extension));
        $mime = strtolower(trim($mime));

        return match (true) {
            $extension === 'pdf' => ['label' => 'PDF', 'kind' => 'pdf', 'mark' => 'PDF', 'background' => '#e11d2e', 'foreground' => '#ffffff'],
            in_array($extension, ['doc', 'docx', 'rtf', 'odt'], true) => ['label' => Str::upper($extension), 'kind' => 'document', 'mark' => 'W', 'background' => '#185abd', 'foreground' => '#ffffff'],
            in_array($extension, ['xls', 'xlsx', 'csv', 'ods'], true) => ['label' => Str::upper($extension), 'kind' => 'sheet', 'mark' => 'X', 'background' => '#107c41', 'foreground' => '#ffffff'],
            in_array($extension, ['ppt', 'pptx', 'odp'], true) => ['label' => Str::upper($extension), 'kind' => 'presentation', 'mark' => 'P', 'background' => '#c43e1c', 'foreground' => '#ffffff'],
            in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp'], true) || str_starts_with($mime, 'image/') => ['label' => 'IMG', 'kind' => 'image', 'mark' => '', 'background' => '#9333ea', 'foreground' => '#ffffff'],
            in_array($extension, ['zip', 'rar', '7z', 'tar', 'gz'], true) => ['label' => 'ZIP', 'kind' => 'archive', 'mark' => 'ZIP', 'background' => '#64748b', 'foreground' => '#ffffff'],
            in_array($extension, ['txt', 'md', 'log'], true) || str_starts_with($mime, 'text/') => ['label' => 'TXT', 'kind' => 'text', 'mark' => 'T', 'background' => '#0284c7', 'foreground' => '#ffffff'],
            default => ['label' => $extension !== '' && $extension !== 'file' ? Str::upper(Str::limit($extension, 4, '')) : 'FILE', 'kind' => 'file', 'mark' => '', 'background' => '#64748b', 'foreground' => '#ffffff'],
        };
    }

    /**
     * @return array<int, mixed>
     */
    protected static function tableActions(): array
    {
        $actions = [];

        $open = Action::make('open')
            ->label('Открыть')
            ->icon('heroicon-o-eye')
            ->url(fn (MarketDocument $record): ?string => filled($record->file_path)
                ? route('filament.admin.market-documents.open', ['document' => $record])
                : null)
            ->openUrlInNewTab()
            ->visible(fn (MarketDocument $record): bool => filled($record->file_path) && blank($record->archived_at));

        $download = Action::make('download')
            ->label('Скачать')
            ->icon('heroicon-o-arrow-down-tray')
            ->url(fn (MarketDocument $record): ?string => filled($record->file_path)
                ? route('filament.admin.market-documents.download', ['document' => $record])
                : null)
            ->visible(fn (MarketDocument $record): bool => filled($record->file_path) && blank($record->archived_at));

        $share = Action::make('share')
            ->label('Поделиться')
            ->icon('heroicon-o-share')
            ->color('gray')
            ->modalHeading('Поделиться файлом')
            ->modalSubmitActionLabel('Отправить')
            ->visible(fn (MarketDocument $record): bool => blank($record->archived_at) && static::canShareDocument($record))
            ->form(static::shareForm('Напишите короткое сообщение к файлу, если нужно.'))
            ->action(fn (MarketDocument $record, array $data): mixed => static::shareDocument($record, $data));

        $move = Action::make('move')
            ->label('Перенести')
            ->icon('heroicon-o-folder-arrow-down')
            ->color('gray')
            ->modalHeading('Перенести файл')
            ->modalSubmitActionLabel('Перенести')
            ->visible(fn (MarketDocument $record): bool => blank($record->archived_at) && static::canManageDocument($record))
            ->form(fn (MarketDocument $record): array => static::moveForm($record))
            ->action(fn (MarketDocument $record, array $data): mixed => static::moveDocument($record, $data));

        $delete = Action::make('archive')
            ->label('В корзину')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Переместить файл в корзину?')
            ->modalDescription('Файл исчезнет из диска, но его можно будет восстановить из корзины до автоматической очистки.')
            ->modalSubmitActionLabel('В корзину')
            ->visible(fn (MarketDocument $record): bool => blank($record->archived_at) && static::canManageDocument($record))
            ->action(fn (MarketDocument $record): mixed => static::archiveDocument($record));

        $restore = Action::make('restore')
            ->label('Восстановить')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('success')
            ->visible(fn (MarketDocument $record): bool => filled($record->archived_at) && static::canManageDocument($record))
            ->action(fn (MarketDocument $record): mixed => static::restoreDocument($record));

        $destroy = Action::make('destroy')
            ->label('Удалить окончательно')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Удалить файл окончательно?')
            ->modalDescription('Файл будет удалён из хранилища без возможности восстановления.')
            ->modalSubmitActionLabel('Удалить окончательно')
            ->visible(fn (MarketDocument $record): bool => filled($record->archived_at) && static::canManageDocument($record))
            ->action(fn (MarketDocument $record): mixed => static::destroyDocument($record));

        $actions[] = ActionGroup::make([$open, $download, $share, $move, $delete, $restore, $destroy])
            ->label('Действия')
            ->icon('heroicon-o-ellipsis-vertical')
            ->iconButton()
            ->color('gray')
            ->tooltip('Действия');

        return $actions;
    }

    /**
     * @return array<int, mixed>
     */
    protected static function tableToolbarActions(): array
    {
        if (! class_exists(BulkAction::class)) {
            return [];
        }

        $download = BulkAction::make('download_selected')
            ->label('Скачать')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->deselectRecordsAfterCompletion()
            ->visible(fn (HasTable $livewire): bool => ! static::isTrashContext($livewire))
            ->action(fn (EloquentCollection $records): mixed => static::downloadDocuments($records));

        $share = BulkAction::make('share_selected')
            ->label('Поделиться')
            ->icon('heroicon-o-share')
            ->color('gray')
            ->modalHeading('Поделиться выбранными файлами')
            ->modalSubmitActionLabel('Отправить')
            ->deselectRecordsAfterCompletion()
            ->visible(fn (HasTable $livewire): bool => ! static::isTrashContext($livewire))
            ->form(static::shareForm('Напишите короткое сообщение к файлам, если нужно.'))
            ->action(fn (array $data, EloquentCollection $records): mixed => static::shareDocuments($records, $data));

        $move = BulkAction::make('move_selected')
            ->label('Перенести')
            ->icon('heroicon-o-folder-arrow-down')
            ->color('gray')
            ->modalHeading('Перенести выбранные файлы')
            ->modalSubmitActionLabel('Перенести')
            ->deselectRecordsAfterCompletion()
            ->visible(fn (HasTable $livewire): bool => static::canBulkManageDocuments($livewire))
            ->form(static::moveForm())
            ->action(fn (array $data, EloquentCollection $records): mixed => static::moveDocuments($records, $data));

        $delete = BulkAction::make('archive_selected')
            ->label('В корзину')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Переместить выбранные файлы в корзину?')
            ->modalDescription('Файлы исчезнут из диска, но их можно будет восстановить из корзины до автоматической очистки.')
            ->modalSubmitActionLabel('В корзину')
            ->deselectRecordsAfterCompletion()
            ->visible(fn (HasTable $livewire): bool => static::canBulkManageDocuments($livewire))
            ->action(fn (EloquentCollection $records): mixed => static::archiveDocuments($records));

        $restore = BulkAction::make('restore_selected')
            ->label('Восстановить')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('success')
            ->deselectRecordsAfterCompletion()
            ->visible(fn (HasTable $livewire): bool => static::canBulkManageTrash($livewire))
            ->action(fn (EloquentCollection $records): mixed => static::restoreDocuments($records));

        $destroy = BulkAction::make('destroy_selected')
            ->label('Удалить окончательно')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Удалить выбранные файлы окончательно?')
            ->modalDescription('Файлы будут удалены из хранилища без возможности восстановления.')
            ->modalSubmitActionLabel('Удалить окончательно')
            ->deselectRecordsAfterCompletion()
            ->visible(fn (HasTable $livewire): bool => static::canBulkManageTrash($livewire))
            ->action(fn (EloquentCollection $records): mixed => static::destroyDocuments($records));

        $actions = [$download, $share, $move, $delete, $restore, $destroy];

        return class_exists(BulkActionGroup::class)
            ? [
                BulkActionGroup::make($actions)
                    ->label('Действия')
                    ->icon('heroicon-o-ellipsis-horizontal')
                    ->color('gray')
                    ->tooltip('Действия'),
            ]
            : $actions;
    }

    /**
     * @return array<int, mixed>
     */
    protected static function shareForm(string $messagePlaceholder): array
    {
        return [
            Forms\Components\Select::make('recipient_id')
                ->label('Получатель')
                ->options(fn (): array => static::shareRecipientOptions())
                ->searchable()
                ->preload()
                ->required()
                ->reactive(),
            Forms\Components\CheckboxList::make('channels')
                ->label('Как отправить')
                ->options(fn (Get $get): array => static::shareChannelOptions($get('recipient_id') ? (int) $get('recipient_id') : null))
                ->default(['dialog'])
                ->required()
                ->columns(1)
                ->helperText('Telegram появится в списке только если он подключен у получателя.'),
            Forms\Components\Textarea::make('message')
                ->label('Сообщение')
                ->rows(3)
                ->maxLength(1000)
                ->placeholder($messagePlaceholder),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    protected static function moveForm(?MarketDocument $record = null): array
    {
        $defaultVisibility = $record?->visibility ?: MarketDocument::VISIBILITY_PERSONAL;

        return [
            Forms\Components\Select::make('visibility')
                ->label('Куда перенести')
                ->options([
                    MarketDocument::VISIBILITY_PERSONAL => 'Мой диск',
                    MarketDocument::VISIBILITY_SHARED => 'Общий диск',
                ])
                ->default($defaultVisibility)
                ->required()
                ->reactive(),
            Forms\Components\Select::make('folder_id')
                ->label('Папка')
                ->options(fn (Get $get): array => static::moveFolderOptions(
                    $record,
                    (string) ($get('visibility') ?: $defaultVisibility),
                ))
                ->default('0')
                ->searchable()
                ->preload()
                ->required(),
        ];
    }

    /**
     * @return array<int, string>
     */
    protected static function shareRecipientOptions(): array
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return [];
        }

        return User::query()
            ->whereKeyNot((int) $user->id)
            ->when(! $user->isSuperAdmin(), fn (Builder $query): Builder => $query->where('market_id', (int) $user->market_id))
            ->whereNotNull('market_id')
            ->whereDoesntHave('roles', fn (Builder $query): Builder => $query->whereIn('name', ['merchant', 'merchant-user', 'buyer', 'tenant']))
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name', 'email', 'telegram_chat_id'])
            ->mapWithKeys(function (User $recipient): array {
                $name = trim((string) $recipient->name);
                $email = trim((string) $recipient->email);
                $label = $name !== '' ? $name : ($email !== '' ? $email : ('Сотрудник #' . $recipient->id));

                return [(int) $recipient->id => $label];
            })
            ->all();
    }

    /**
     * @return array<string, string>
     */
    protected static function shareChannelOptions(?int $recipientId): array
    {
        $options = [
            'dialog' => 'Личным сообщением',
            'mail' => 'На почту',
        ];

        if ($recipientId) {
            $hasTelegram = User::query()
                ->whereKey($recipientId)
                ->whereNotNull('telegram_chat_id')
                ->exists();

            if ($hasTelegram) {
                $options['telegram'] = 'В Telegram';
            }
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    protected static function moveFolderOptions(?MarketDocument $record, string $visibility): array
    {
        if (! DbSchema::hasTable('market_document_folders')) {
            return ['0' => 'В корень'];
        }

        $user = Filament::auth()->user();

        if (! $user) {
            return ['0' => 'В корень'];
        }

        $visibility = MarketDocument::normalizeVisibility($visibility);
        $marketId = $record?->market_id
            ?: static::selectedMarketIdFromSession()
            ?: ($user->market_id ? (int) $user->market_id : null);
        $ownerUserId = $visibility === MarketDocument::VISIBILITY_PERSONAL ? (int) $user->id : null;

        $folders = MarketDocumentFolder::query()
            ->visibleFor($user)
            ->whereNull('archived_at')
            ->where('visibility', $visibility)
            ->when($marketId, fn (Builder $query): Builder => $query->where('market_id', (int) $marketId))
            ->when(
                $visibility === MarketDocument::VISIBILITY_PERSONAL,
                fn (Builder $query): Builder => $query->where('owner_user_id', $ownerUserId),
            )
            ->with('parent')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->limit(200)
            ->get()
            ->mapWithKeys(fn (MarketDocumentFolder $folder): array => [(string) $folder->id => $folder->displayName()])
            ->all();

        return ['0' => 'В корень'] + $folders;
    }

    /**
     * @param array<string, mixed> $data
     */
    protected static function moveDocument(MarketDocument $record, array $data): mixed
    {
        return static::moveDocuments(new EloquentCollection([$record]), $data);
    }

    /**
     * @param EloquentCollection<int, MarketDocument> $records
     */
    protected static function downloadDocuments(EloquentCollection $records): mixed
    {
        $records = $records
            ->filter(fn ($record): bool => $record instanceof MarketDocument && filled($record->file_path))
            ->values();

        if ($records->isEmpty()) {
            throw ValidationException::withMessages([
                'records' => 'В выбранных записях нет файлов для скачивания.',
            ]);
        }

        if (! class_exists(ZipArchive::class)) {
            throw ValidationException::withMessages([
                'records' => 'На сервере недоступна сборка архива. Сообщите администратору.',
            ]);
        }

        $disk = Storage::disk(MarketDocument::storageDisk());
        $directory = storage_path('app/market-document-downloads');

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw ValidationException::withMessages([
                'records' => 'Не удалось подготовить архив для скачивания.',
            ]);
        }

        $zipPath = $directory . DIRECTORY_SEPARATOR . 'documents-' . now()->format('Ymd-His') . '-' . Str::lower(Str::random(8)) . '.zip';
        $zip = new ZipArchive();

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw ValidationException::withMessages([
                'records' => 'Не удалось создать архив для скачивания.',
            ]);
        }

        $usedNames = [];
        $added = 0;

        foreach ($records as $record) {
            $path = (string) $record->file_path;

            try {
                if (! $disk->exists($path)) {
                    continue;
                }

                $archiveName = static::uniqueArchiveFileName($record->resolvedFileName(), $usedNames);
                $absolutePath = method_exists($disk, 'path') ? $disk->path($path) : null;

                if ($absolutePath && is_file($absolutePath)) {
                    $zip->addFile($absolutePath, $archiveName);
                } else {
                    $zip->addFromString($archiveName, $disk->get($path));
                }

                $added++;
            } catch (\Throwable) {
                continue;
            }
        }

        $zip->close();

        if ($added === 0) {
            @unlink($zipPath);

            throw ValidationException::withMessages([
                'records' => 'Не удалось найти выбранные файлы в хранилище.',
            ]);
        }

        return response()
            ->download($zipPath, 'documents-' . now()->format('Y-m-d-His') . '.zip')
            ->deleteFileAfterSend(true);
    }

    /**
     * @param array<string, bool> $usedNames
     */
    protected static function uniqueArchiveFileName(string $name, array &$usedNames): string
    {
        $name = trim(str_replace(["\0", '/', '\\'], [' ', '-', '-'], $name));
        $name = preg_replace('/\s+/u', ' ', $name) ?: '';
        $name = $name !== '' ? $name : 'document';

        $base = pathinfo($name, PATHINFO_FILENAME);
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $base = trim($base) !== '' ? trim($base) : 'document';
        $suffix = $extension !== '' ? '.' . $extension : '';
        $candidate = $base . $suffix;
        $index = 2;

        while (isset($usedNames[Str::lower($candidate)])) {
            $candidate = $base . ' (' . $index . ')' . $suffix;
            $index++;
        }

        $usedNames[Str::lower($candidate)] = true;

        return $candidate;
    }

    /**
     * @param EloquentCollection<int, MarketDocument> $records
     * @param array<string, mixed> $data
     */
    protected static function moveDocuments(EloquentCollection $records, array $data): mixed
    {
        $user = Filament::auth()->user();
        $records = $records
            ->filter(fn ($record): bool => $record instanceof MarketDocument)
            ->values();

        if (! $user || $records->isEmpty()) {
            abort(403);
        }

        foreach ($records as $record) {
            if (! static::canManageDocument($record)) {
                abort(403);
            }
        }

        $visibility = MarketDocument::normalizeVisibility((string) ($data['visibility'] ?? MarketDocument::VISIBILITY_PERSONAL));
        $folderId = (int) ($data['folder_id'] ?? 0);
        $folder = null;

        if ($folderId > 0) {
            $folder = MarketDocumentFolder::query()
                ->visibleFor($user)
                ->whereNull('archived_at')
                ->whereKey($folderId)
                ->first();

            if (! $folder instanceof MarketDocumentFolder) {
                throw ValidationException::withMessages([
                    'folder_id' => 'Выберите папку, к которой у вас есть доступ.',
                ]);
            }

            $visibility = $folder->visibility;
        }

        foreach ($records as $record) {
            if ($folder) {
                $record->folder_id = (int) $folder->id;
                $record->market_id = (int) $folder->market_id;
                $record->visibility = $folder->visibility;
                $record->owner_user_id = $folder->owner_user_id;
            } else {
                $record->folder_id = null;
                $record->visibility = $visibility;
                $record->market_id = $record->market_id
                    ?: static::selectedMarketIdFromSession()
                    ?: ($user->market_id ? (int) $user->market_id : null);
                $record->owner_user_id = $visibility === MarketDocument::VISIBILITY_PERSONAL
                    ? (int) $user->id
                    : null;
            }

            $record->save();
        }

        \Filament\Notifications\Notification::make()
            ->title($records->count() === 1 ? 'Файл перенесен' : 'Файлы перенесены')
            ->success()
            ->send();

        return null;
    }

    protected static function archiveDocument(MarketDocument $record): mixed
    {
        return static::archiveDocuments(new EloquentCollection([$record]));
    }

    /**
     * @param EloquentCollection<int, MarketDocument> $records
     */
    protected static function archiveDocuments(EloquentCollection $records): mixed
    {
        $records = $records
            ->filter(fn ($record): bool => $record instanceof MarketDocument)
            ->values();

        if ($records->isEmpty()) {
            abort(403);
        }

        foreach ($records as $record) {
            if (! static::canManageDocument($record)) {
                abort(403);
            }
        }

        foreach ($records as $record) {
            $record->forceFill(['archived_at' => now()])->save();
        }

        \Filament\Notifications\Notification::make()
            ->title($records->count() === 1 ? 'Файл перемещён в корзину' : 'Файлы перемещены в корзину')
            ->success()
            ->send();

        return null;
    }

    protected static function restoreDocument(MarketDocument $record): mixed
    {
        return static::restoreDocuments(new EloquentCollection([$record]));
    }

    /**
     * @param EloquentCollection<int, MarketDocument> $records
     */
    protected static function restoreDocuments(EloquentCollection $records): mixed
    {
        $records = $records
            ->filter(fn ($record): bool => $record instanceof MarketDocument)
            ->values();

        if ($records->isEmpty()) {
            abort(403);
        }

        foreach ($records as $record) {
            if (! static::canManageDocument($record)) {
                abort(403);
            }
        }

        foreach ($records as $record) {
            $record->forceFill(['archived_at' => null])->save();
        }

        \Filament\Notifications\Notification::make()
            ->title($records->count() === 1 ? 'Файл восстановлен' : 'Файлы восстановлены')
            ->success()
            ->send();

        return null;
    }

    protected static function destroyDocument(MarketDocument $record): mixed
    {
        return static::destroyDocuments(new EloquentCollection([$record]));
    }

    /**
     * @param EloquentCollection<int, MarketDocument> $records
     */
    protected static function destroyDocuments(EloquentCollection $records): mixed
    {
        $records = $records
            ->filter(fn ($record): bool => $record instanceof MarketDocument)
            ->values();

        if ($records->isEmpty()) {
            abort(403);
        }

        foreach ($records as $record) {
            if (! static::canManageDocument($record)) {
                abort(403);
            }
        }

        foreach ($records as $record) {
            static::deleteStoredFile($record);
            $record->delete();
        }

        \Filament\Notifications\Notification::make()
            ->title($records->count() === 1 ? 'Файл удалён окончательно' : 'Файлы удалены окончательно')
            ->success()
            ->send();

        return null;
    }

    protected static function deleteStoredFile(MarketDocument $record): void
    {
        if (blank($record->file_path)) {
            return;
        }

        $storage = Storage::disk(MarketDocument::storageDisk());
        $path = (string) $record->file_path;

        try {
            if ($storage->exists($path)) {
                $storage->delete($path);
            }
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'records' => 'Не удалось удалить файл из хранилища. Попробуйте позже или обратитесь к администратору.',
            ]);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    protected static function shareDocument(MarketDocument $record, array $data): mixed
    {
        $author = Filament::auth()->user();

        if (! $author || ! static::canShareDocument($record)) {
            abort(403);
        }

        if (! DbSchema::hasTable('market_document_shares')) {
            throw ValidationException::withMessages([
                'recipient_id' => 'Доступ к файлам еще не подготовлен. Обновите базу данных.',
            ]);
        }

        $recipientId = (int) ($data['recipient_id'] ?? 0);
        $recipient = User::query()
            ->whereKey($recipientId)
            ->whereNotNull('market_id')
            ->first();

        if (! $recipient instanceof User || (int) $recipient->id === (int) $author->id) {
            throw ValidationException::withMessages([
                'recipient_id' => 'Выберите сотрудника, которому нужно открыть доступ.',
            ]);
        }

        if (! $author->isSuperAdmin() && (int) $recipient->market_id !== (int) $author->market_id) {
            abort(403);
        }

        $channels = array_values(array_intersect(
            array_map('strval', (array) ($data['channels'] ?? [])),
            array_keys(static::shareChannelOptions((int) $recipient->id)),
        ));

        if ($channels === []) {
            throw ValidationException::withMessages([
                'channels' => 'Выберите хотя бы один способ отправки.',
            ]);
        }

        MarketDocumentShare::query()->updateOrCreate(
            [
                'market_document_id' => (int) $record->id,
                'shared_with_user_id' => (int) $recipient->id,
            ],
            [
                'shared_by_user_id' => (int) $author->id,
                'access_level' => MarketDocumentShare::ACCESS_VIEW,
                'revoked_at' => null,
            ],
        );

        $message = trim((string) ($data['message'] ?? ''));
        $body = static::shareMessageText($record, $author, $message);

        if (in_array('dialog', $channels, true)) {
            app(StaffConversationService::class)->startConversation(
                $author,
                $recipient,
                'Файл: ' . $record->resolvedFileName(),
                $body,
                [static::shareAttachmentPayload($record)],
            );
        }

        $notificationChannels = array_values(array_intersect($channels, ['mail', 'telegram']));
        if ($notificationChannels !== []) {
            $recipient->notify(new MarketDocumentSharedNotification($record, $author, $message, $notificationChannels));
        }

        \Filament\Notifications\Notification::make()
            ->title('Доступ открыт')
            ->body('Файл появится у получателя в разделе «Со мной поделились».')
            ->success()
            ->send();

        return null;
    }

    /**
     * @param EloquentCollection<int, MarketDocument> $records
     * @param array<string, mixed> $data
     */
    protected static function shareDocuments(EloquentCollection $records, array $data): mixed
    {
        $author = Filament::auth()->user();
        $records = $records
            ->filter(fn ($record): bool => $record instanceof MarketDocument)
            ->values();

        if (! $author || $records->isEmpty()) {
            abort(403);
        }

        if (! DbSchema::hasTable('market_document_shares')) {
            throw ValidationException::withMessages([
                'recipient_id' => 'Доступ к файлам еще не подготовлен. Обновите базу данных.',
            ]);
        }

        foreach ($records as $record) {
            if (! static::canShareDocument($record)) {
                abort(403);
            }
        }

        $recipientId = (int) ($data['recipient_id'] ?? 0);
        $recipient = User::query()
            ->whereKey($recipientId)
            ->whereNotNull('market_id')
            ->first();

        if (! $recipient instanceof User || (int) $recipient->id === (int) $author->id) {
            throw ValidationException::withMessages([
                'recipient_id' => 'Выберите сотрудника, которому нужно открыть доступ.',
            ]);
        }

        if (! $author->isSuperAdmin() && (int) $recipient->market_id !== (int) $author->market_id) {
            abort(403);
        }

        $channels = array_values(array_intersect(
            array_map('strval', (array) ($data['channels'] ?? [])),
            array_keys(static::shareChannelOptions((int) $recipient->id)),
        ));

        if ($channels === []) {
            throw ValidationException::withMessages([
                'channels' => 'Выберите хотя бы один способ отправки.',
            ]);
        }

        foreach ($records as $record) {
            MarketDocumentShare::query()->updateOrCreate(
                [
                    'market_document_id' => (int) $record->id,
                    'shared_with_user_id' => (int) $recipient->id,
                ],
                [
                    'shared_by_user_id' => (int) $author->id,
                    'access_level' => MarketDocumentShare::ACCESS_VIEW,
                    'revoked_at' => null,
                ],
            );
        }

        $message = trim((string) ($data['message'] ?? ''));
        $body = static::shareDocumentsMessageText($records, $author, $message);

        if (in_array('dialog', $channels, true)) {
            app(StaffConversationService::class)->startConversation(
                $author,
                $recipient,
                'Файлы: ' . $records->count(),
                $body,
                $records
                    ->map(fn (MarketDocument $record): array => static::shareAttachmentPayload($record))
                    ->values()
                    ->all(),
            );
        }

        $notificationChannels = array_values(array_intersect($channels, ['mail', 'telegram']));
        if ($notificationChannels !== []) {
            foreach ($records as $record) {
                $recipient->notify(new MarketDocumentSharedNotification($record, $author, $message, $notificationChannels));
            }
        }

        \Filament\Notifications\Notification::make()
            ->title('Доступ открыт')
            ->body('Файлы появятся у получателя в разделе «Со мной поделились».')
            ->success()
            ->send();

        return null;
    }

    protected static function shareMessageText(MarketDocument $record, User $author, string $message): string
    {
        $authorName = trim((string) ($author->name ?: $author->email));
        $text = ($authorName !== '' ? $authorName : 'Сотрудник') . ' поделился с вами файлом: ' . $record->resolvedFileName();

        if ($message !== '') {
            $text .= PHP_EOL . $message;
        }

        return $text;
    }

    /**
     * @param EloquentCollection<int, MarketDocument> $records
     */
    protected static function shareDocumentsMessageText(EloquentCollection $records, User $author, string $message): string
    {
        $authorName = trim((string) ($author->name ?: $author->email));
        $sender = $authorName !== '' ? $authorName : 'Сотрудник';
        $fileList = $records
            ->take(8)
            ->map(fn (MarketDocument $record): string => '- ' . $record->resolvedFileName())
            ->implode(PHP_EOL);

        $text = $sender . ' поделился с вами файлами:' . PHP_EOL . $fileList;
        $extraCount = max(0, $records->count() - 8);

        if ($extraCount > 0) {
            $text .= PHP_EOL . 'И еще файлов: ' . $extraCount;
        }

        if ($message !== '') {
            $text .= PHP_EOL . $message;
        }

        return $text;
    }

    /**
     * @return array<string, mixed>
     */
    protected static function shareAttachmentPayload(MarketDocument $record): array
    {
        $mime = trim((string) ($record->mime_type ?? ''));

        return [
            'path' => (string) $record->file_path,
            'name' => $record->resolvedFileName(),
            'mime' => $mime !== '' ? $mime : 'application/octet-stream',
            'size' => (int) ($record->file_size ?? 0),
            'is_image' => str_starts_with($mime, 'image/'),
        ];
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

    private static function selectedFolderFromRequest(): ?MarketDocumentFolder
    {
        if (! DbSchema::hasTable('market_document_folders')) {
            return null;
        }

        $folderId = request()->query('folder');

        if (! filled($folderId)) {
            return null;
        }

        return MarketDocumentFolder::query()
            ->visibleFor(Filament::auth()->user())
            ->whereNull('archived_at')
            ->with('parent')
            ->find((int) $folderId);
    }
}
