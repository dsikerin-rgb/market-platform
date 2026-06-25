<?php

declare(strict_types=1);

namespace App\Filament\Resources\MarketDocumentResource\Pages;

use App\Filament\Resources\MarketDocumentResource;
use App\Models\Market;
use App\Models\MarketDocument;
use App\Models\MarketDocumentActivityEvent;
use App\Models\MarketDocumentFolder;
use App\Support\MarketDocuments\MarketDocumentActivityLogger;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema as DbSchema;
use Illuminate\Support\Facades\Validator;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use RuntimeException;

class ListMarketDocuments extends ListRecords
{
    use WithFileUploads;

    protected static string $resource = MarketDocumentResource::class;

    protected static ?string $title = 'Диск';

    protected array $queryString = [
        'activeTab' => ['as' => 'tab', 'except' => 'personal'],
        'selectedFolderId' => ['as' => 'folder', 'except' => null],
    ];

    public ?string $activeTab = 'personal';

    public ?int $selectedFolderId = null;

    public bool $isCreateFolderModalOpen = false;

    public string $newFolderName = '';

    public mixed $documentUpload = null;

    public function mount(): void
    {
        parent::mount();

        $legacyTab = request()->query('activeTab');
        $currentTab = request()->query('tab');

        if (filled($legacyTab) && blank($currentTab)) {
            $this->activeTab = (string) $legacyTab;
        }

        if (! in_array($this->activeTab, ['personal', 'shared', 'shared-with-me', 'all', MarketDocumentResource::TAB_TRASH], true)) {
            $this->activeTab = 'personal';
        }

        $this->selectedFolderId = $this->selectedFolder()?->id;

        if ($this->activeTab === MarketDocumentResource::TAB_TRASH) {
            $this->selectedFolderId = null;
        }

        if ($this->selectedFolderId && ! in_array($this->activeTab, ['all', MarketDocumentResource::TAB_TRASH], true)) {
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
                    ->whereNull('archived_at')
                    ->where('visibility', MarketDocument::VISIBILITY_PERSONAL)
                    ->when($userId > 0 && ! ($user?->isSuperAdmin() ?? false), fn (Builder $inner): Builder => $inner->where('owner_user_id', $userId))
            ),
            'shared' => $this->makeTab(
                $tabClass,
                'Общий',
                fn (Builder $query): Builder => $query
                    ->whereNull('archived_at')
                    ->where('visibility', MarketDocument::VISIBILITY_SHARED)
            ),
            'shared-with-me' => $this->makeTab(
                $tabClass,
                'Со мной поделились',
                fn (Builder $query): Builder => $this->scopeSharedWithMe($query)->whereNull('archived_at')
            ),
            'all' => $this->makeTab(
                $tabClass,
                'Все документы',
                fn (Builder $query): Builder => $query->whereNull('archived_at')
            ),
            MarketDocumentResource::TAB_TRASH => $this->makeTab(
                $tabClass,
                'Корзина',
                fn (Builder $query): Builder => $this->scopeTrash($query)
            ),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getDocumentWorkspaceHeaderActions(): array
    {
        return [];
    }

    public function openCreateFolderModal(): void
    {
        $this->newFolderName = '';
        $this->resetErrorBag('newFolderName');
        $this->isCreateFolderModalOpen = true;
    }

    public function closeCreateFolderModal(): void
    {
        $this->isCreateFolderModalOpen = false;
        $this->newFolderName = '';
        $this->resetErrorBag('newFolderName');
    }

    public function createFolder(): void
    {
        $user = Filament::auth()->user();
        abort_unless($user && MarketDocumentResource::canCreate(), 403);

        Validator::make(
            ['newFolderName' => $this->newFolderName],
            ['newFolderName' => ['required', 'string', 'max:255']],
            [],
            ['newFolderName' => 'название папки'],
        )->validate();

        $parentFolder = $this->selectedFolder();
        $visibility = $parentFolder?->visibility ?: $this->currentTargetVisibility();
        $marketId = $parentFolder?->market_id ?: $this->resolvedMarketId();
        $ownerUserId = $visibility === MarketDocument::VISIBILITY_PERSONAL
            ? ($parentFolder?->owner_user_id ?: (int) $user->id)
            : null;
        $parentId = $parentFolder?->id;

        $folder = MarketDocumentFolder::query()->create([
            'market_id' => $marketId ?: $user->market_id,
            'owner_user_id' => $ownerUserId,
            'parent_id' => $parentId,
            'visibility' => $visibility,
            'name' => trim($this->newFolderName),
            'sort_order' => $this->nextFolderSortOrder(
                $visibility,
                $marketId ? (int) $marketId : null,
                $ownerUserId,
                $parentId ? (int) $parentId : null,
            ),
        ]);

        $this->closeCreateFolderModal();

        Notification::make()
            ->title('Папка создана')
            ->success()
            ->send();

        $this->redirect($this->workspaceUrl($folder->visibility, (int) $folder->id));
    }

    public function updatedDocumentUpload(): void
    {
        $user = Filament::auth()->user();
        abort_unless($user && MarketDocumentResource::canCreate(), 403);

        $file = $this->documentUpload;

        if (! $file instanceof TemporaryUploadedFile) {
            return;
        }

        $parentFolder = $this->selectedFolder();
        $visibility = $parentFolder?->visibility ?: $this->currentTargetVisibility();
        $marketId = $parentFolder?->market_id ?: $this->resolvedMarketId();
        $ownerUserId = $visibility === MarketDocument::VISIBILITY_PERSONAL
            ? ($parentFolder?->owner_user_id ?: (int) $user->id)
            : null;
        $folderId = $parentFolder?->id;
        $directory = MarketDocument::storageDirectory(
            $marketId ? (int) $marketId : null,
            $ownerUserId,
            $visibility,
            $folderId ? (int) $folderId : null,
        );
        $path = $file->store($directory, MarketDocument::storageDisk());

        if (! is_string($path) || $path === '') {
            Notification::make()
                ->title('Не удалось загрузить документ')
                ->danger()
                ->send();

            $this->documentUpload = null;

            return;
        }

        $originalName = trim($file->getClientOriginalName());

        $document = MarketDocument::query()->create([
            'market_id' => $marketId ?: $user->market_id,
            'owner_user_id' => $ownerUserId,
            'uploaded_by_user_id' => (int) $user->id,
            'folder_id' => $folderId,
            'visibility' => $visibility,
            'category' => MarketDocument::CATEGORY_GENERAL,
            'title' => $originalName !== '' ? $originalName : null,
            'file_path' => $path,
            'original_name' => $originalName !== '' ? $originalName : basename($path),
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
        ]);

        app(MarketDocumentActivityLogger::class)->log(
            $document,
            MarketDocumentActivityEvent::ACTION_UPLOADED,
            $user,
            null,
            ['source' => 'workspace_upload'],
        );

        $this->documentUpload = null;

        Notification::make()
            ->title('Документ добавлен')
            ->success()
            ->send();

        $this->dispatch('$refresh');
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

        if ($this->activeTab === MarketDocumentResource::TAB_TRASH) {
            return $this->scopeTrash($query);
        }

        if ($this->selectedFolderId) {
            return $query
                ->whereNull('archived_at')
                ->where('folder_id', $this->selectedFolderId);
        }

        if ($this->activeTab === 'shared-with-me') {
            return $this->scopeSharedWithMe($query)->whereNull('archived_at');
        }

        return $query->whereNull('archived_at');
    }

    /**
     * @return array{activeTab:string,sections:array<int, array<string, mixed>>,folderGroups:array<string, list<array{id:int,name:string,section:string,url:string,isActive:bool,documents:int}>>,contentFolders:list<array{id:int,name:string,url:string,documents:int,folders:int}>,activeFolder:?array{id:int,name:string,section:string,url:string,isActive:bool,documents:int},canViewActivityLog:bool,activityLogUrl:?string}
     */
    public function documentWorkspaceData(): array
    {
        $activeTab = in_array($this->activeTab, ['personal', 'shared', 'shared-with-me', 'all', MarketDocumentResource::TAB_TRASH], true)
            ? (string) $this->activeTab
            : 'personal';

        return [
            'activeTab' => $activeTab,
            'sections' => $this->documentSectionCards($activeTab),
            'folderGroups' => [
                'personal' => $this->foldersForSection('personal'),
                'shared' => $this->foldersForSection('shared'),
            ],
            'contentFolders' => $this->contentFolders($activeTab),
            'activeFolder' => $this->activeFolderData(),
            'canViewActivityLog' => MarketDocumentResource::canViewActivityLog(),
            'activityLogUrl' => MarketDocumentResource::activityLogUrl(),
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
                'key' => 'shared-with-me',
                'label' => 'Со мной поделились',
                'description' => 'Файлы, которыми поделились',
                'icon' => 'heroicon-o-share',
                'url' => $this->workspaceUrl('shared-with-me'),
                'isActive' => $activeTab === 'shared-with-me' && ! $this->selectedFolderId,
                'documents' => $this->documentsCount('shared-with-me'),
                'folders' => 0,
            ],
            [
                'key' => MarketDocumentResource::TAB_TRASH,
                'label' => 'Корзина',
                'description' => 'Удалённые файлы',
                'icon' => 'heroicon-o-trash',
                'url' => $this->workspaceUrl(MarketDocumentResource::TAB_TRASH),
                'isActive' => $activeTab === MarketDocumentResource::TAB_TRASH,
                'documents' => $this->documentsCount(MarketDocumentResource::TAB_TRASH),
                'folders' => 0,
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
        } elseif ($section === 'shared-with-me') {
            $this->scopeSharedWithMe($query);
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
     * @return list<array{id:int,name:string,url:string,documents:int,folders:int}>
     */
    private function contentFolders(string $activeTab): array
    {
        if (! DbSchema::hasTable('market_document_folders')) {
            return [];
        }

        $selectedFolder = $this->selectedFolder();

        if ($selectedFolder) {
            $visibility = (string) $selectedFolder->visibility;
            $parentId = (int) $selectedFolder->id;
        } elseif (in_array($activeTab, [MarketDocument::VISIBILITY_PERSONAL, MarketDocument::VISIBILITY_SHARED], true)) {
            $visibility = $activeTab;
            $parentId = null;
        } else {
            return [];
        }

        return $this->folderBaseQuery()
            ->whereNull('archived_at')
            ->where('visibility', $visibility)
            ->when(
                $parentId,
                fn (Builder $query): Builder => $query->where('parent_id', $parentId),
                fn (Builder $query): Builder => $query->whereNull('parent_id'),
            )
            ->withCount([
                'documents' => fn (Builder $query): Builder => $query->whereNull('archived_at'),
                'children' => fn (Builder $query): Builder => $query->whereNull('archived_at'),
            ])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->limit(100)
            ->get()
            ->map(fn (MarketDocumentFolder $folder): array => [
                'id' => (int) $folder->id,
                'name' => trim((string) $folder->name) !== '' ? trim((string) $folder->name) : 'Папка',
                'url' => $this->workspaceUrl($folder->visibility, (int) $folder->id),
                'documents' => (int) ($folder->documents_count ?? 0),
                'folders' => (int) ($folder->children_count ?? 0),
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

        if ($section === MarketDocumentResource::TAB_TRASH) {
            return (int) $this->scopeTrash($this->documentBaseQuery())->count();
        }

        $query = $this->documentBaseQuery()->whereNull('archived_at');

        if ($section === 'personal') {
            $query->where('visibility', MarketDocument::VISIBILITY_PERSONAL);
        } elseif ($section === 'shared') {
            $query->where('visibility', MarketDocument::VISIBILITY_SHARED);
        } elseif ($section === 'shared-with-me') {
            $this->scopeSharedWithMe($query);
        }

        return (int) $query->count();
    }

    private function scopeTrash(Builder $query): Builder
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        $query->whereNotNull('archived_at');

        if ($user->isSuperAdmin()) {
            return $query;
        }

        if (! $user->market_id) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->isMarketAdmin()) {
            return $query->where('market_id', (int) $user->market_id);
        }

        return $query
            ->where('market_id', (int) $user->market_id)
            ->where(function (Builder $inner) use ($user): void {
                $inner
                    ->where(function (Builder $personal) use ($user): void {
                        $personal
                            ->where('visibility', MarketDocument::VISIBILITY_PERSONAL)
                            ->where('owner_user_id', (int) $user->id);
                    })
                    ->orWhere(function (Builder $shared) use ($user): void {
                        $shared
                            ->where('visibility', MarketDocument::VISIBILITY_SHARED)
                            ->where('uploaded_by_user_id', (int) $user->id);
                    });
            });
    }

    private function scopeSharedWithMe(Builder $query): Builder
    {
        $user = Filament::auth()->user();

        if (! $user || ! DbSchema::hasTable('market_document_shares')) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('shares', function (Builder $shareQuery) use ($user): void {
            $shareQuery
                ->where('shared_with_user_id', (int) $user->id)
                ->whereNull('revoked_at');
        });
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

    private function currentTargetVisibility(): string
    {
        return $this->activeTab === MarketDocument::VISIBILITY_SHARED
            ? MarketDocument::VISIBILITY_SHARED
            : MarketDocument::VISIBILITY_PERSONAL;
    }

    private function resolvedMarketId(): ?int
    {
        $user = Filament::auth()->user();

        if ($selectedMarketId = $this->selectedMarketIdFromSession()) {
            return $selectedMarketId;
        }

        if ($user?->market_id) {
            return (int) $user->market_id;
        }

        if ($user?->isSuperAdmin()) {
            $marketId = Market::query()->orderBy('id')->value('id');

            return filled($marketId) ? (int) $marketId : null;
        }

        return null;
    }

    private function nextFolderSortOrder(string $visibility, ?int $marketId, ?int $ownerUserId, ?int $parentId): int
    {
        if (! DbSchema::hasTable('market_document_folders')) {
            return 0;
        }

        $max = MarketDocumentFolder::query()
            ->where('visibility', MarketDocument::normalizeVisibility($visibility))
            ->when($marketId, fn (Builder $query): Builder => $query->where('market_id', $marketId))
            ->when(
                $visibility === MarketDocument::VISIBILITY_PERSONAL && $ownerUserId,
                fn (Builder $query): Builder => $query->where('owner_user_id', $ownerUserId),
            )
            ->when(
                $parentId,
                fn (Builder $query): Builder => $query->where('parent_id', $parentId),
                fn (Builder $query): Builder => $query->whereNull('parent_id'),
            )
            ->max('sort_order');

        return ((int) $max) + 10;
    }

    private function selectedFolder(): ?MarketDocumentFolder
    {
        if ($this->activeTab === MarketDocumentResource::TAB_TRASH) {
            return null;
        }

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
