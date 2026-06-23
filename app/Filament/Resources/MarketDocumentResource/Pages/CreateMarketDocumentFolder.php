<?php

declare(strict_types=1);

namespace App\Filament\Resources\MarketDocumentResource\Pages;

use App\Filament\Resources\MarketDocumentResource;
use App\Models\Market;
use App\Models\MarketDocument;
use App\Models\MarketDocumentFolder;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Schema as DbSchema;

class CreateMarketDocumentFolder extends Page
{
    protected static string $resource = MarketDocumentResource::class;

    protected static ?string $title = 'Новая папка';

    protected string $view = 'filament.resources.market-document-resource.pages.create-folder';

    /**
     * @var array<string, mixed>
     */
    public array $data = [];

    public ?MarketDocumentFolder $parentFolder = null;

    public static function canAccess(array $parameters = []): bool
    {
        return MarketDocumentResource::canCreate();
    }

    public function mount(): void
    {
        $this->parentFolder = $this->resolveParentFolder();

        $this->form->fill([
            'market_id' => $this->parentFolder?->market_id ?: ($this->selectedMarketIdFromSession() ?: Filament::auth()->user()?->market_id),
            'visibility' => $this->parentFolder?->visibility ?: $this->defaultVisibility(),
            'owner_user_id' => $this->parentFolder?->owner_user_id ?: Filament::auth()->id(),
            'parent_id' => $this->parentFolder?->id,
            'name' => '',
            'sort_order' => 0,
        ]);
    }

    public function form(Schema $schema): Schema
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
                ->dehydrated(true);

        return $schema
            ->statePath('data')
            ->components([
                Section::make('Размещение')
                    ->schema([
                        $marketField,

                        Forms\Components\Select::make('visibility')
                            ->label('Раздел')
                            ->options(MarketDocument::visibilityOptions())
                            ->required()
                            ->reactive(),

                        Forms\Components\Select::make('owner_user_id')
                            ->label('Владелец личного раздела')
                            ->options(fn (Get $get): array => MarketDocumentResource::ownerOptions($get('market_id') ? (int) $get('market_id') : null))
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
                    ])
                    ->columns(2),

                Section::make('Папка')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Название папки')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),

                        Forms\Components\TextInput::make('sort_order')
                            ->label('Порядок')
                            ->numeric()
                            ->default(0)
                            ->minValue(0),
                    ])
                    ->columns(2),
            ]);
    }

    public function create(): void
    {
        $user = Filament::auth()->user();
        abort_unless($user, 403);

        $data = $this->form->getState();
        $visibility = MarketDocument::normalizeVisibility((string) ($data['visibility'] ?? MarketDocument::VISIBILITY_PERSONAL));
        $marketId = $data['market_id'] ?? null;
        $ownerUserId = $visibility === MarketDocument::VISIBILITY_PERSONAL
            ? ($data['owner_user_id'] ?? $user->id)
            : null;

        $folder = MarketDocumentFolder::query()->create([
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

        $this->redirect(MarketDocumentResource::getUrl('index', [
            'tab' => $folder->visibility,
            'folder' => (int) $folder->id,
        ]));
    }

    public function cancel(): void
    {
        $this->redirect(MarketDocumentResource::getUrl('index', $this->parentFolder
            ? ['tab' => $this->parentFolder->visibility, 'folder' => (int) $this->parentFolder->id]
            : ['tab' => $this->defaultVisibility()]));
    }

    private function resolveParentFolder(): ?MarketDocumentFolder
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

    private function defaultVisibility(): string
    {
        return request()->query('tab') === MarketDocument::VISIBILITY_SHARED
            ? MarketDocument::VISIBILITY_SHARED
            : MarketDocument::VISIBILITY_PERSONAL;
    }

    private function selectedMarketIdFromSession(): ?int
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
