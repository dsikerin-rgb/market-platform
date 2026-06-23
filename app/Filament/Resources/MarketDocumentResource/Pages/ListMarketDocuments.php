<?php

declare(strict_types=1);

namespace App\Filament\Resources\MarketDocumentResource\Pages;

use App\Filament\Resources\MarketDocumentResource;
use App\Models\MarketDocument;
use App\Models\MarketDocumentFolder;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema as DbSchema;
use RuntimeException;

class ListMarketDocuments extends ListRecords
{
    protected static string $resource = MarketDocumentResource::class;

    protected static ?string $title = 'Диск';

    protected array $queryString = [
        'activeTab' => ['as' => 'tab', 'except' => 'personal'],
        'selectedFolderId' => ['as' => 'folder', 'except' => null],
    ];

    public ?string $activeTab = 'personal';

    public ?int $selectedFolderId = null;

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

        $this->selectedFolderId = $this->selectedFolder()?->id;

        if ($this->selectedFolderId && $this->activeTab !== 'all') {
            $folder = $this->selectedFolder();

            if ($folder) {
                $this->activeTab = $folder->visibility;
            }
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
                'Общий',
                fn (Builder $query): Builder => $query->where('visibility', MarketDocument::VISIBILITY_SHARED)
            ),
            'all' => $tabClass::make('Все документы'),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getDocumentWorkspaceHeaderActions(): array
    {
        $folderAction = Actions\Action::make('createFolder')
            ->label('Создать папку')
            ->icon('heroicon-o-folder-plus')
            ->color('gray')
            ->url(fn (): string => MarketDocumentResource::getUrl(
                'create-folder',
                $this->selectedFolderId ? ['folder' => $this->selectedFolderId] : ['tab' => $this->activeTab],
            ))
            ->extraAttributes(['wire:navigate' => true]);

        $createAction = Actions\Action::make('createDocument')
            ->label('Добавить документ')
            ->icon('heroicon-o-arrow-up-tray')
            ->url(fn (): string => MarketDocumentResource::getUrl(
                'create',
                $this->selectedFolderId ? ['folder' => $this->selectedFolderId] : [],
            ))
            ->extraAttributes(['wire:navigate' => true]);
        return [$folderAction, $createAction];
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

    protected function getTableQuery(): Builder
    {
        $query = parent::getTableQuery();

        return $this->selectedFolderId
            ? $query->where('folder_id', $this->selectedFolderId)
            : $query;
    }

    /**
     * @return array{activeTab:string,sections:array<int, array<string, mixed>>,folderGroups:array<string, list<array{id:int,name:string,section:string,url:string,isActive:bool,documents:int}>>,activeFolder:?array{id:int,name:string,section:string,url:string,isActive:bool,documents:int}}
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
            'activeFolder' => $this->activeFolderData(),
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
                'url' => $this->workspaceUrl('personal'),
                'isActive' => $activeTab === 'personal' && ! $this->selectedFolderId,
                'documents' => $this->documentsCount('personal'),
                'folders' => $this->foldersCount('personal'),
            ],
            [
                'key' => 'shared',
                'label' => 'Общий',
                'description' => 'Файлы рынка',
                'icon' => 'heroicon-o-users',
                'url' => $this->workspaceUrl('shared'),
                'isActive' => $activeTab === 'shared' && ! $this->selectedFolderId,
                'documents' => $this->documentsCount('shared'),
                'folders' => $this->foldersCount('shared'),
            ],
            [
                'key' => 'all',
                'label' => 'Все документы',
                'description' => 'Весь доступный архив',
                'icon' => 'heroicon-o-folder-open',
                'url' => $this->workspaceUrl('all'),
                'isActive' => $activeTab === 'all' && ! $this->selectedFolderId,
                'documents' => $this->documentsCount('all'),
                'folders' => $this->foldersCount('all'),
            ],
        ];
    }

    /**
     * @return list<array{id:int,name:string,section:string,url:string,isActive:bool,documents:int}>
     */
    private function foldersForSection(string $section): array
    {
        if (! DbSchema::hasTable('market_document_folders')) {
            return [];
        }

        $query = $this->folderBaseQuery()
            ->whereNull('archived_at')
            ->withCount([
                'documents' => fn (Builder $query): Builder => $query->whereNull('archived_at'),
            ])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->limit(200);

        if ($section === 'personal') {
            $query->where('visibility', MarketDocument::VISIBILITY_PERSONAL);
        } elseif ($section === 'shared') {
            $query->where('visibility', MarketDocument::VISIBILITY_SHARED);
        }

        return $query
            ->get()
            ->map(fn (MarketDocumentFolder $folder): array => [
                'id' => (int) $folder->id,
                'name' => $folder->displayName(),
                'section' => $folder->visibility === MarketDocument::VISIBILITY_SHARED ? 'Общий' : 'Личный',
                'url' => $this->workspaceUrl($folder->visibility, (int) $folder->id),
                'isActive' => (int) $this->selectedFolderId === (int) $folder->id,
                'documents' => (int) ($folder->documents_count ?? 0),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{id:int,name:string,section:string,url:string,isActive:bool,documents:int}|null
     */
    private function activeFolderData(): ?array
    {
        $folder = $this->selectedFolder();

        if (! $folder) {
            return null;
        }

        return [
            'id' => (int) $folder->id,
            'name' => $folder->displayName(),
            'section' => $folder->visibility === MarketDocument::VISIBILITY_SHARED ? 'Общий' : 'Личный',
            'url' => $this->workspaceUrl($folder->visibility, (int) $folder->id),
            'isActive' => true,
            'documents' => $this->documentsCountForFolder((int) $folder->id),
        ];
    }

    private function workspaceUrl(string $tab, ?int $folderId = null): string
    {
        $params = ['tab' => $tab];

        if ($folderId) {
            $params['folder'] = $folderId;
        }

        return MarketDocumentResource::getUrl('index', $params);
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

    private function documentsCountForFolder(int $folderId): int
    {
        if (! DbSchema::hasTable('market_documents')) {
            return 0;
        }

        return (int) $this->documentBaseQuery()
            ->whereNull('archived_at')
            ->where('folder_id', $folderId)
            ->count();
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

    private function selectedFolder(): ?MarketDocumentFolder
    {
        if (! $this->selectedFolderId || ! DbSchema::hasTable('market_document_folders')) {
            return null;
        }

        return $this->folderBaseQuery()
            ->whereNull('archived_at')
            ->with('parent')
            ->find((int) $this->selectedFolderId);
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
