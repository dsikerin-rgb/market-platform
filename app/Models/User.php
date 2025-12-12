<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Market;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser
{
    /**
     * @use HasFactory<\Database\Factories\UserFactory>
     */
    use HasFactory;
    use Notifiable;
    use HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'market_id',
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
        ];
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole('super-admin');
    }

    public function isMarketAdmin(): bool
    {
        return $this->hasRole('market-admin');
    }

    public function canAccessPanel(Panel $panel): bool
    {
        // Локально пускаем всех, чтобы не мешать разработке
        if (app()->environment('local')) {
            return true;
        }

        // Для admin-панели в бою — только роли управления рынком
        if ($panel->getId() === 'admin') {
            return $this->hasAnyRole([
                'super-admin',
                'market-admin',
                'market-manager',
                'market-operator',
            ]);
        }

        return false;
    }
}
