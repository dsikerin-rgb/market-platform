<?php

declare(strict_types=1);

namespace App\Filament\Resources\MarketDocumentResource\Pages;

use App\Filament\Resources\MarketDocumentResource;
use App\Models\Market;
use App\Models\MarketDocument;
use App\Models\MarketDocumentFolder;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema as DbSchema;
use RuntimeException;

class ListMarketDocuments extends ListRecords
{
    protected static string $resource = MarketDocumentResource::class;

    protected static ?string $title = 'Документы';

    protected array $queryString = [
        'activeTab' => ['as' => 'tab', 'except' => 'personal'],
    ];

    public ?string $activeTab = 'personal';

    public function mount(): void
    {
        parent::mount();

        $legacyTab = request()->query('activeTab');
        $currentTab = request()->query('tab');

        if (filled($legacyTab) && blank($currentTab)) {
            $this->activeTab = (string) $legacyTab;
        }

        if (! in_array($this->activeTab, ['personal', 'shared', 'all'], true)) {
            $this->activeTab = 'personal';
        }
    }

    public function getBreadcrumb(): string
    {
        return 'Список';
    }

    public function getView(): string
    {
        return 'filament.resources.market-document-resource.pages.index';
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'personal';
    }

    public function getTabs(): array
    {
        $tabClass = static::resolveTabClass();
        $user = Filament::auth()->user();
        $userId = $user ? (int) $user->id : 0;

        return [
            'personal' => $this->makeTab(
                $tabClass,
                'Личный диск',
                fn (Builder $query): Builder => $query
                    ->where('visibility', MarketDocument::VISIBILITY_PERSONAL)
                    ->when($userId > 0 && ! ($user?->isSuperAdmin() ?? false), fn (Builder $inner): Builder => $inner->where('owner_user_id', $userId))
            ),
            'shared' => $this->makeTab(
                $tabClass,
                'Общий диск',
                fn (Builder $query): Builder => $query->where('visibility', MarketDocument::VISIBILITY_SHARED)
            ),
            'all' => $tabClass::make('Все документы'),
        ];
    }

    protected function getHeaderActions(): array
    {
        $folderAction = Actions\Action::make('createFolder')
            ->label('Создать папку')
            ->icon('heroicon-o-folder-plus')
            ->color('gray')
            ->modalHeading('Новая папка')
            ->form($this->folderActionForm())
            ->action(function (array $data): void {
                $user = Filament::auth()->user();

                abort_unless($user, 403);

                $visibility = MarketDocument::normalizeVisibility((string) ($data['visibility'] ?? MarketDocument::VISIBILITY_PERSONAL));
                $marketId = $data['market_id'] ?? null;
                $ownerUserId = $visibility === MarketDocument::VISIBILITY_PERSONAL
                    ? ($data['owner_user_id'] ?? $user->id)
                    : null;

                MarketDocumentFolder::query()->create([
                    'market_id' => $marketId ?: $user->market_id,
                    'owner_user_id' => $ownerUserId,
                    'parent_id' => $data['parent_id'] ?? null,
                    'visibility' => $visibility,
                    'name' => trim((string) ($data['name'] ?? '')),
                    'sort_order' => max(0, (int) ($data['sort_order'] ?? 0)),
                ]);

                Notification::make()
                    ->title('Папка создана')
                    ->success()
                    ->send();
            });

        $createAction = Actions\CreateAction::make()
            ->label('Добавить документ')
            ->icon('heroicon-o-arrow-up-tray');

        if (method_exists($createAction, 'slideOver')) {
            $createAction->slideOver();
        }

        if (method_exists($createAction, 'modalWidth')) {
            $createAction->modalWidth('5xl');
        }

        return [$folderAction, $createAction];
    }

    /**
     * @return array<int, mixed>
     */
    protected function folderActionForm(): array
    {
        $user = Filament::auth()->user();
        $selectedMarketId = $this->selectedMarketIdFromSession();
        $isSuperAdmin = (bool) $user && $user->isSuperAdmin();

        $marketField = $isSuperAdmin && ! $selectedMarketId
            ? Forms\Components\Select::make('market_id')
                ->label('Рынок')
                ->options(fn (): array => Market::query()->orderBy('name')->pluck('name', 'id')->all())
                ->required()
                ->searchable()
                ->preload()
                ->reactive()
            : Forms\Components\Hidden::make('market_id')
                ->default($selectedMarketId ?: $user?->market_id)
                ->dehydrated(true);

        return [
            $marketField,

            Forms\Components\Select::make('visibility')
                ->label('Раздел')
                ->options(MarketDocument::visibilityOptions())
                ->default(MarketDocument::VISIBILITY_PERSONAL)
                ->required()
                ->reactive(),

            Forms\Components\Select::make('owner_user_id')
                ->label('Владелец личного раздела')
                ->options(fn (Get $get): array => MarketDocumentResource::ownerOptions($get('market_id') ? (int) $get('market_id') : null))
                ->default($user?->id)
                ->searchable()
                ->preload()
                ->required(fn (Get $get): bool => $get('visibility') === MarketDocument::VISIBILITY_PERSONAL)
                ->visible(fn (Get $get): bool => $get('visibility') === MarketDocument::VISIBILITY_PERSONAL)
                ->disabled(fn (): bool => ! MarketDocumentResource::canManageOtherOwners())
                ->dehydrated(true),

            Forms\Components\Select::make('parent_id')
                ->label('Внутри папки')
                ->options(fn (Get $get): array => MarketDocumentResource::folderOptions(
                    $get('market_id') ? (int) $get('market_id') : null,
                    (string) ($get('visibility') ?: MarketDocument::VISIBILITY_PERSONAL),
                    $get('owner_user_id') ? (int) $get('owner_user_id') : null,
                ))
                ->placeholder('В корне раздела')
                ->searchable()
                ->preload(),

            Forms\Components\TextInput::make('name')
                ->label('Название папки')
                ->required()
                ->maxLength(255),

            Forms\Components\TextInput::make('sort_order')
                ->label('Порядок')
                ->numeric()
                ->default(0)
                ->minValue(0),
        ];
    }

    protected static function resolveTabClass(): string
    {
        if (class_exists(\Filament\Schemas\Components\Tabs\Tab::class)) {
            return \Filament\Schemas\Components\Tabs\Tab::class;
        }

        if (class_exists(\Filament\Resources\Components\Tab::class)) {
            return \Filament\Resources\Components\Tab::class;
        }

        throw new RuntimeException('Filament Tab class not found for this version.');
    }

    protected function makeTab(string $tabClass, string $label, callable $modifyQueryUsing): object
    {
        $tab = $tabClass::make($label);

        if (method_exists($tab, 'modifyQueryUsing')) {
            $tab->modifyQueryUsing($modifyQueryUsing);
        }

        return $tab;
    }

    /**
     * @return array{activeTab:string,sections:array<int, array<string, mixed>>,folderGroups:array<string, list<array{name:string,section:string,documents:int}>>}
     */
    public function documentWorkspaceData(): array
    {
        $activeTab = in_array($this->activeTab, ['personal', 'shared', 'all'], true)
            ? (string) $this->activeTab
            : 'personal';

        return [
            'activeTab' => $activeTab,
            'sections' => $this->documentSectionCards($activeTab),
            'folderGroups' => [
                'personal' => $this->foldersForSection('personal'),
                'shared' => $this->foldersForSection('shared'),
            ],
        ];
    }

    /**
     * @return array<int, array{key:string,label:string,description:string,icon:string,url:string,isActive:bool,documents:int,folders:int}>
     */
    private function documentSectionCards(string $activeTab): array
    {
        return [
            [
                'key' => 'personal',
                'label' => 'Личный диск',
                'description' => 'Файлы сотрудника',
                'icon' => 'heroicon-o-user-circle',
                'url' => MarketDocumentResource::getUrl('index', ['tab' => 'personal']),
                'isActive' => $activeTab === 'personal',
                'documents' => $this->documentsCount('personal'),
                'folders' => $this->foldersCount('personal'),
            ],
            [
                'key' => 'shared',
                'label' => 'Общий диск',
                'description' => 'Файлы рынка',
                'icon' => 'heroicon-o-users',
                'url' => MarketDocumentResource::getUrl('index', ['tab' => 'shared']),
                'isActive' => $activeTab === 'shared',
                'documents' => $this->documentsCount('shared'),
                'folders' => $this->foldersCount('shared'),
            ],
            [
                'key' => 'all',
                'label' => 'Все документы',
                'description' => 'Весь доступный архив',
                'icon' => 'heroicon-o-folder-open',
                'url' => MarketDocumentResource::getUrl('index', ['tab' => 'all']),
                'isActive' => $activeTab === 'all',
                'documents' => $this->documentsCount('all'),
                'folders' => $this->foldersCount('all'),
            ],
        ];
    }

    /**
     * @return list<array{name:string,section:string,documents:int}>
     */
    private function foldersForSection(string $section): array
    {
        if (! DbSchema::hasTable('market_document_folders')) {
            return [];
        }

        $query = $this->folderBaseQuery()
            ->whereNull('archived_at')
            ->withCount('documents')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->limit(8);

        if ($section === 'personal') {
            $query->where('visibility', MarketDocument::VISIBILITY_PERSONAL);
        } elseif ($section === 'shared') {
            $query->where('visibility', MarketDocument::VISIBILITY_SHARED);
        }

        return $query
            ->get()
            ->map(fn (MarketDocumentFolder $folder): array => [
                'name' => $folder->displayName(),
                'section' => $folder->visibility === MarketDocument::VISIBILITY_SHARED ? 'Общий диск' : 'Личный диск',
                'documents' => (int) ($folder->documents_count ?? 0),
            ])
            ->values()
            ->all();
    }

    private function documentsCount(string $section): int
    {
        if (! DbSchema::hasTable('market_documents')) {
            return 0;
        }

        $query = $this->documentBaseQuery()->whereNull('archived_at');

        if ($section === 'personal') {
            $query->where('visibility', MarketDocument::VISIBILITY_PERSONAL);
        } elseif ($section === 'shared') {
            $query->where('visibility', MarketDocument::VISIBILITY_SHARED);
        }

        return (int) $query->count();
    }

    private function foldersCount(string $section): int
    {
        if (! DbSchema::hasTable('market_document_folders')) {
            return 0;
        }

        $query = $this->folderBaseQuery()->whereNull('archived_at');

        if ($section === 'personal') {
            $query->where('visibility', MarketDocument::VISIBILITY_PERSONAL);
        } elseif ($section === 'shared') {
            $query->where('visibility', MarketDocument::VISIBILITY_SHARED);
        }

        return (int) $query->count();
    }

    private function documentBaseQuery(): Builder
    {
        $query = MarketDocument::query();
        $user = Filament::auth()->user();

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->isSuperAdmin()) {
            $selectedMarketId = $this->selectedMarketIdFromSession();

            return filled($selectedMarketId)
                ? $query->where('market_id', (int) $selectedMarketId)
                : $query;
        }

        return $query->visibleFor($user);
    }

    private function folderBaseQuery(): Builder
    {
        $query = MarketDocumentFolder::query();
        $user = Filament::auth()->user();

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->isSuperAdmin()) {
            $selectedMarketId = $this->selectedMarketIdFromSession();

            return filled($selectedMarketId)
                ? $query->where('market_id', (int) $selectedMarketId)
                : $query;
        }

        return $query->visibleFor($user);
    }

    protected function selectedMarketIdFromSession(): ?int
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
