<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\MarketWriteGuard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MarketDocument extends Model
{
    public const VISIBILITY_SHARED = 'shared';
    public const VISIBILITY_PERSONAL = 'personal';

    public const CATEGORY_GENERAL = 'general';
    public const CATEGORY_CONTRACTS = 'contracts';
    public const CATEGORY_ACTS = 'acts';
    public const CATEGORY_SCHEMES = 'schemes';
    public const CATEGORY_PHOTOS = 'photos';
    public const CATEGORY_REGULATIONS = 'regulations';
    public const CATEGORY_OTHER = 'other';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'market_id',
        'owner_user_id',
        'uploaded_by_user_id',
        'folder_id',
        'visibility',
        'category',
        'title',
        'description',
        'file_path',
        'original_name',
        'mime_type',
        'file_size',
        'related_type',
        'related_id',
        'archived_at',
    ];

    protected $casts = [
        'market_id' => 'integer',
        'owner_user_id' => 'integer',
        'uploaded_by_user_id' => 'integer',
        'folder_id' => 'integer',
        'file_size' => 'integer',
        'related_id' => 'integer',
        'archived_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (MarketDocument $document): void {
            $user = Auth::user();

            if (! $document->uploaded_by_user_id && $user) {
                $document->uploaded_by_user_id = (int) $user->id;
            }

            if (! $document->market_id && $user?->market_id) {
                $document->market_id = (int) $user->market_id;
            }

            if ($document->visibility === self::VISIBILITY_PERSONAL && ! $document->owner_user_id && $user) {
                $document->owner_user_id = (int) $user->id;
            }
        });

        static::saving(function (MarketDocument $document): void {
            $document->visibility = self::normalizeVisibility((string) $document->visibility);
            $document->category = self::normalizeCategory((string) $document->category);

            if ($document->folder_id && Schema::hasTable('market_document_folders')) {
                $folder = MarketDocumentFolder::query()->find((int) $document->folder_id);

                if ($folder) {
                    if ($document->market_id) {
                        app(MarketWriteGuard::class)->assertSameMarketId(
                            $document->market_id,
                            $folder->market_id,
                            'folder_id',
                            'Selected folder belongs to another market.',
                        );
                    }

                    $document->market_id = $folder->market_id;
                    $document->visibility = $folder->visibility;
                    $document->owner_user_id = $folder->owner_user_id;
                }
            }

            if ($document->visibility === self::VISIBILITY_SHARED) {
                $document->owner_user_id = null;
            }

            if (blank($document->related_type) || blank($document->related_id)) {
                $document->related_type = null;
                $document->related_id = null;
            }

            $document->syncFileMetadata();

            if (blank($document->title)) {
                $document->title = $document->resolvedFileName();
            }

            $document->assertOwnerBelongsToDocumentMarket();
            $document->assertRelatedBelongsToDocumentMarket();
        });
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(MarketDocumentFolder::class, 'folder_id');
    }

    public function related(): MorphTo
    {
        return $this->morphTo('related');
    }

    public function shares(): HasMany
    {
        return $this->hasMany(MarketDocumentShare::class);
    }

    public function activityEvents(): HasMany
    {
        return $this->hasMany(MarketDocumentActivityEvent::class, 'market_document_id');
    }

    public function scopeVisibleFor(Builder $query, ?User $user): Builder
    {
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->isSuperAdmin()) {
            return $query;
        }

        if (! $user->market_id) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $outer) use ($user): void {
            $outer->where(function (Builder $marketScope) use ($user): void {
                $marketScope
                    ->where('market_id', (int) $user->market_id)
                    ->where(function (Builder $inner) use ($user): void {
                        $inner
                            ->where('visibility', self::VISIBILITY_SHARED)
                            ->orWhere('owner_user_id', (int) $user->id);
                    });
            });

            if (Schema::hasTable('market_document_shares')) {
                $outer->orWhereHas('shares', function (Builder $shareQuery) use ($user): void {
                    $shareQuery
                        ->where('shared_with_user_id', (int) $user->id)
                        ->whereNull('revoked_at');
                });
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public static function visibilityOptions(): array
    {
        return [
            self::VISIBILITY_SHARED => 'Общие документы',
            self::VISIBILITY_PERSONAL => 'Личные документы',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function categoryOptions(): array
    {
        return [
            self::CATEGORY_GENERAL => 'Общее',
            self::CATEGORY_CONTRACTS => 'Договоры',
            self::CATEGORY_ACTS => 'Акты',
            self::CATEGORY_SCHEMES => 'Схемы',
            self::CATEGORY_PHOTOS => 'Фото',
            self::CATEGORY_REGULATIONS => 'Регламенты',
            self::CATEGORY_OTHER => 'Другое',
        ];
    }

    public static function normalizeVisibility(string $visibility): string
    {
        return array_key_exists($visibility, self::visibilityOptions())
            ? $visibility
            : self::VISIBILITY_PERSONAL;
    }

    public static function normalizeCategory(string $category): string
    {
        return array_key_exists($category, self::categoryOptions())
            ? $category
            : self::CATEGORY_GENERAL;
    }

    public static function storageDisk(): string
    {
        return (string) config('market_documents.disk', 'public');
    }

    public static function storageDirectory(?int $marketId, ?int $ownerUserId, string $visibility, ?int $folderId = null): string
    {
        $base = trim((string) config('market_documents.directory', 'market-documents'), '/');
        $market = $marketId && $marketId > 0 ? "market-{$marketId}" : 'market-unassigned';
        $folder = $folderId && $folderId > 0 ? "/folder-{$folderId}" : '';

        if (self::normalizeVisibility($visibility) === self::VISIBILITY_PERSONAL) {
            $owner = $ownerUserId && $ownerUserId > 0 ? "user-{$ownerUserId}" : 'user-unassigned';

            return "{$base}/{$market}/personal/{$owner}{$folder}";
        }

        return "{$base}/{$market}/shared{$folder}";
    }

    public function visibilityLabel(): string
    {
        return self::visibilityOptions()[$this->visibility] ?? 'Личные документы';
    }

    public function categoryLabel(): string
    {
        return self::categoryOptions()[$this->category] ?? 'Общее';
    }

    public function resolvedFileName(): string
    {
        $name = trim((string) ($this->original_name ?: basename((string) $this->file_path)));

        return $name !== '' ? $name : 'Документ';
    }

    public function displayFileName(): string
    {
        $title = trim((string) $this->title);
        $fileName = $this->resolvedFileName();

        if ($title === '') {
            return $fileName;
        }

        $extension = strtolower((string) pathinfo($fileName, PATHINFO_EXTENSION));

        if ($extension === '') {
            return $title;
        }

        $titleExtension = strtolower((string) pathinfo($title, PATHINFO_EXTENSION));

        return $titleExtension === ''
            ? "{$title}.{$extension}"
            : $title;
    }

    public function fileSizeLabel(): string
    {
        $bytes = (int) $this->file_size;

        if ($bytes >= 1024 * 1024) {
            return number_format($bytes / (1024 * 1024), 1, ',', ' ') . ' МБ';
        }

        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 0, ',', ' ') . ' КБ';
        }

        return $bytes > 0 ? "{$bytes} Б" : '—';
    }

    public function relatedLabel(): string
    {
        if (blank($this->related_type) || blank($this->related_id)) {
            return 'Не связано';
        }

        $related = $this->related;

        if ($related instanceof Tenant) {
            return 'Арендатор: ' . $related->display_name;
        }

        if ($related instanceof TenantContract) {
            $number = trim((string) ($related->number ?? ''));

            return 'Договор: ' . ($number !== '' ? $number : ('#' . $related->id));
        }

        if ($related instanceof MarketSpace) {
            $number = trim((string) ($related->number ?: $related->code ?: ''));

            return 'Место: ' . ($number !== '' ? $number : ('#' . $related->id));
        }

        if ($related instanceof Task) {
            $title = trim((string) ($related->title ?? ''));

            return 'Задача: ' . ($title !== '' ? $title : ('#' . $related->id));
        }

        if ($related instanceof TenantRequest) {
            $subject = trim((string) ($related->subject ?? ''));

            return 'Обращение: ' . ($subject !== '' ? $subject : ('#' . $related->id));
        }

        return class_basename((string) $this->related_type) . ' #' . $this->related_id;
    }

    public function temporaryDownloadUrl(): ?string
    {
        if (blank($this->file_path)) {
            return null;
        }

        $diskName = self::storageDisk();
        $storage = Storage::disk($diskName);
        $name = Str::ascii($this->displayFileName()) ?: 'document';

        try {
            if (method_exists($storage, 'temporaryUrl')) {
                return $storage->temporaryUrl(
                    (string) $this->file_path,
                    now()->addMinutes(10),
                    ['ResponseContentDisposition' => 'attachment; filename="' . addslashes($name) . '"'],
                );
            }
        } catch (\Throwable) {
            // Fall back to a regular disk URL below.
        }

        try {
            return $storage->url((string) $this->file_path);
        } catch (\Throwable) {
            return null;
        }
    }

    public function syncFileMetadata(): void
    {
        if (blank($this->file_path)) {
            return;
        }

        $storage = Storage::disk(self::storageDisk());
        $path = (string) $this->file_path;

        try {
            if (! $this->file_size && $storage->exists($path)) {
                $this->file_size = (int) $storage->size($path);
            }
        } catch (\Throwable) {
            // Metadata is optional; the document record should still be saved.
        }

        try {
            if (blank($this->mime_type) && $storage->exists($path)) {
                $this->mime_type = $storage->mimeType($path) ?: null;
            }
        } catch (\Throwable) {
            // Metadata is optional; the document record should still be saved.
        }

        if (blank($this->original_name)) {
            $this->original_name = basename($path);
        }
    }

    private function assertOwnerBelongsToDocumentMarket(): void
    {
        if (! $this->owner_user_id || ! $this->market_id || ! Schema::hasTable('users')) {
            return;
        }

        $ownerMarketId = User::query()
            ->whereKey((int) $this->owner_user_id)
            ->value('market_id');

        if ($ownerMarketId === null) {
            return;
        }

        app(MarketWriteGuard::class)->assertSameMarketId(
            $this->market_id,
            $ownerMarketId,
            'owner_user_id',
            'Document owner belongs to another market.',
        );
    }

    private function assertRelatedBelongsToDocumentMarket(): void
    {
        if (! $this->market_id || blank($this->related_type) || blank($this->related_id)) {
            return;
        }

        $relatedType = (string) $this->related_type;

        if (! is_a($relatedType, Model::class, true)) {
            return;
        }

        /** @var Model|null $related */
        $related = $relatedType::query()->find((int) $this->related_id);

        if (! $related || ! array_key_exists('market_id', $related->getAttributes())) {
            return;
        }

        app(MarketWriteGuard::class)->assertSameMarketId(
            $this->market_id,
            $related->getAttribute('market_id'),
            'related_id',
            'Related record belongs to another market.',
        );
    }
}
