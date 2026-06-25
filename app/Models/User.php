<?php

namespace App\Models;

use App\Support\AdminPanelImpersonation;
use App\Support\MarketplaceMediaStorage;
use Filament\Models\Contracts\HasAvatar;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser, HasAvatar
{
    /**
     * @use HasFactory<\Database\Factories\UserFactory>
     */
    use HasFactory;
    use Notifiable;
    use HasRoles;

    /**
     * Spatie Permission guard.
     */
    protected string $guard_name = 'web';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'job_title',
        'department',
        'manager_id',
        'organization_level',
        'birth_date',
        'password',
        'market_id',
        'tenant_id',
        'telegram_chat_id',
        'telegram_profile',
        'telegram_linked_at',
        'notification_preferences',
        'staff_avatar_path',
        'staff_avatar_color',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'telegram_profile' => 'array',
            'telegram_linked_at' => 'datetime',
            'notification_preferences' => 'array',
            'last_seen_at' => 'datetime',
            'birth_date' => 'date',
            'manager_id' => 'integer',
            'organization_level' => 'integer',
        ];
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function aiProfile(): HasOne
    {
        return $this->hasOne(AiUserProfile::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function directReports(): HasMany
    {
        return $this->hasMany(User::class, 'manager_id');
    }

    public function personalDocuments(): HasMany
    {
        return $this->hasMany(MarketDocument::class, 'owner_user_id');
    }

    public function personalDocumentFolders(): HasMany
    {
        return $this->hasMany(MarketDocumentFolder::class, 'owner_user_id');
    }

    public function uploadedMarketDocuments(): HasMany
    {
        return $this->hasMany(MarketDocument::class, 'uploaded_by_user_id');
    }

    public function sharedMarketDocuments(): HasMany
    {
        return $this->hasMany(MarketDocumentShare::class, 'shared_with_user_id');
    }

    public function tenantSpaces(): BelongsToMany
    {
        return $this->belongsToMany(MarketSpace::class, 'tenant_user_market_spaces', 'user_id', 'market_space_id')
            ->withTimestamps();
    }

    public function marketplaceFavorites(): HasMany
    {
        return $this->hasMany(MarketplaceFavorite::class, 'buyer_user_id');
    }

    public function marketplaceBuyerChats(): HasMany
    {
        return $this->hasMany(MarketplaceChat::class, 'buyer_user_id');
    }

    public function marketplaceChatMessages(): HasMany
    {
        return $this->hasMany(MarketplaceChatMessage::class, 'sender_user_id');
    }

    /**
     * @return list<int>
     */
    public function allowedTenantSpaceIds(): array
    {
        $tenantId = (int) ($this->tenant_id ?? 0);
        if ($tenantId <= 0) {
            return [];
        }

        $scoped = [];
        if (Schema::hasTable('tenant_user_market_spaces')) {
            try {
                $scoped = $this->tenantSpaces()
                    ->select('market_spaces.id')
                    ->where('market_spaces.tenant_id', $tenantId)
                    ->pluck('market_spaces.id')
                    ->map(static fn ($id): int => (int) $id)
                    ->filter(static fn (int $id): bool => $id > 0)
                    ->values()
                    ->all();
            } catch (\Throwable) {
                $scoped = [];
            }
        }

        if ($scoped !== []) {
            return $scoped;
        }

        return MarketSpace::query()
            ->where('tenant_id', $tenantId)
            ->when((int) ($this->market_id ?? 0) > 0, fn (Builder $query): Builder => $query->where('market_id', (int) $this->market_id))
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values()
            ->all();
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole('super-admin');
    }

    public function isMarketAdmin(): bool
    {
        return $this->hasRole('market-admin');
    }

    public function isBuyer(): bool
    {
        return method_exists($this, 'hasRole') && $this->hasRole('buyer');
    }

    public function canSelfManageNotificationPreferences(): bool
    {
        if ($this->isSuperAdmin() || $this->isMarketAdmin()) {
            return true;
        }

        $raw = (array) ($this->notification_preferences ?? []);

        return (bool) ($raw['self_manage'] ?? false);
    }

    public function getFilamentAvatarUrl(): ?string
    {
        return $this->staffAvatarUrl();
    }

    public function staffAvatarUrl(): ?string
    {
        $path = trim((string) ($this->staff_avatar_path ?? ''));

        return $path !== '' ? MarketplaceMediaStorage::previewUrl($path) : null;
    }

    public function staffAvatarColor(): string
    {
        $color = trim((string) ($this->staff_avatar_color ?? ''));

        return preg_match('/^#[0-9a-f]{6}$/i', $color) === 1
            ? $color
            : '#2563eb';
    }

    /**
     * Access to Ops tooling (Horizon, etc.).
     */
    public function canAccessHorizon(): bool
    {
        return $this->isSuperAdmin();
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if ($panel->getId() === 'admin') {
            $effectiveUser = AdminPanelImpersonation::resolveAdminUser($this);

            // Explicit admin roles always win, even if a legacy merchant role is also present.
            if (AdminPanelImpersonation::hasAdminPanelRole($effectiveUser)) {
                return true;
            }

            // Арендаторы и их сотрудники — только в /cabinet
            if (method_exists($this, 'hasAnyRole') && $this->hasAnyRole(['merchant', 'merchant-user'])) {
                return false;
            }

            // Покупатели маркетплейса — не в админку
            if (method_exists($this, 'hasRole') && $this->hasRole('buyer')) {
                return false;
            }

            // Служебные роли tenant/user — не в админку
            if (method_exists($this, 'hasRole') && $this->hasRole('tenant')) {
                return false;
            }

            if (method_exists($this, 'hasRole') && $this->hasRole('user')) {
                return false;
            }

            return false;
        }

        if (method_exists($this, 'hasAnyRole') && $this->hasAnyRole(['merchant', 'merchant-user'])) {
            return false;
        }

        return false;
    }
}
