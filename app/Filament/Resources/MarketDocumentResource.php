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
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema as DbSchema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MarketDocumentResource extends BaseResource
{
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
                IconColumn::make('file_type_icon')
                    ->label('')
                    ->state(fn (MarketDocument $record): string => static::documentTypeMeta(
                        static::documentExtension($record),
                        (string) $record->mime_type,
                    )['kind'])
                    ->icon(fn (string $state): string => static::documentTypeIconName($state))
                    ->color(fn (string $state): string => static::documentTypeIconColor($state))
                    ->tooltip(fn (MarketDocument $record): string => static::documentTypeMeta(
                        static::documentExtension($record),
                        (string) $record->mime_type,
                    )['label'])
                    ->alignCenter(),

                TextColumn::make('title')
                    ->label('Документ')
                    ->formatStateUsing(fn ($state, MarketDocument $record): string => static::documentTitleLabel($record))
                    ->searchable()
                    ->sortable()
                    ->wrap(),

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
            ])
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
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->with(['market', 'owner', 'uploadedBy', 'folder.parent', 'related'])
            ->whereNull('archived_at');

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

    protected static function documentTitleLabel(MarketDocument $record): string
    {
        $title = trim((string) $record->title);
        $fileName = $record->resolvedFileName();

        return $title !== '' ? $title : $fileName;
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
     * @return array{label:string,kind:string}
     */
    protected static function documentTypeMeta(string $extension, string $mime): array
    {
        $extension = strtolower(trim($extension));
        $mime = strtolower(trim($mime));

        return match (true) {
            $extension === 'pdf' => ['label' => 'PDF', 'kind' => 'pdf'],
            in_array($extension, ['doc', 'docx', 'rtf', 'odt'], true) => ['label' => Str::upper($extension), 'kind' => 'document'],
            in_array($extension, ['xls', 'xlsx', 'csv', 'ods'], true) => ['label' => Str::upper($extension), 'kind' => 'sheet'],
            in_array($extension, ['ppt', 'pptx', 'odp'], true) => ['label' => Str::upper($extension), 'kind' => 'presentation'],
            in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp'], true) || str_starts_with($mime, 'image/') => ['label' => 'IMG', 'kind' => 'image'],
            in_array($extension, ['zip', 'rar', '7z', 'tar', 'gz'], true) => ['label' => 'ZIP', 'kind' => 'archive'],
            in_array($extension, ['txt', 'md', 'log'], true) || str_starts_with($mime, 'text/') => ['label' => 'TXT', 'kind' => 'text'],
            default => ['label' => $extension !== '' && $extension !== 'file' ? Str::upper(Str::limit($extension, 4, '')) : 'FILE', 'kind' => 'file'],
        };
    }

    protected static function documentTypeIconName(string $kind): string
    {
        return match ($kind) {
            'pdf' => 'heroicon-o-document-text',
            'document' => 'heroicon-o-document',
            'sheet' => 'heroicon-o-table-cells',
            'presentation' => 'heroicon-o-presentation-chart-bar',
            'image' => 'heroicon-o-photo',
            'archive' => 'heroicon-o-archive-box',
            'text' => 'heroicon-o-document-text',
            default => 'heroicon-o-document',
        };
    }

    protected static function documentTypeIconColor(string $kind): string
    {
        return match ($kind) {
            'pdf' => 'danger',
            'document' => 'info',
            'sheet' => 'success',
            'presentation' => 'warning',
            'image' => 'primary',
            default => 'gray',
        };
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

        $share = class_exists(\Filament\Actions\Action::class)
            ? \Filament\Actions\Action::make('share')
            : (class_exists(\Filament\Tables\Actions\Action::class) ? \Filament\Tables\Actions\Action::make('share') : null);

        if ($share) {
            $share
                ->label('Поделиться')
                ->icon('heroicon-o-share')
                ->color('gray')
                ->modalHeading('Поделиться файлом')
                ->modalSubmitActionLabel('Отправить')
                ->visible(fn (MarketDocument $record): bool => static::canEdit($record))
                ->form([
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
                        ->placeholder('Напишите короткое сообщение к файлу, если нужно.'),
                ])
                ->action(fn (MarketDocument $record, array $data): mixed => static::shareDocument($record, $data));

            $actions[] = $share;
        }

        return $actions;
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
     * @param array<string, mixed> $data
     */
    protected static function shareDocument(MarketDocument $record, array $data): mixed
    {
        $author = Filament::auth()->user();

        if (! $author || ! static::canEdit($record)) {
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
