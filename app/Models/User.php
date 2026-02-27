<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'location_id',
        'is_admin',
        'is_super_admin',
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
            'location_id' => 'integer',
            'is_admin' => 'boolean',
            'is_super_admin' => 'boolean',
        ];
    }

    public const ROLE_MANAGER             = 'manager';
    public const ROLE_DIRECTOR_FINANCIAR  = 'director_financiar';
    public const ROLE_SUPORT_FINANCIAR    = 'suport_financiar';
    public const ROLE_DIRECTOR_ECONOMIC   = 'director_economic';
    public const ROLE_DIRECTOR_VANZARI    = 'director_vanzari';
    public const ROLE_MANAGER_ACHIZITII   = 'manager_achizitii';
    public const ROLE_CONSULTANT_VANZARI  = 'consultant_vanzari';

    public static function roleOptions(): array
    {
        return [
            self::ROLE_MANAGER            => 'Manager',
            self::ROLE_DIRECTOR_FINANCIAR => 'Director Financiar',
            self::ROLE_SUPORT_FINANCIAR   => 'Suport Financiar',
            self::ROLE_DIRECTOR_ECONOMIC  => 'Director Economic',
            self::ROLE_DIRECTOR_VANZARI   => 'Director Vânzări',
            self::ROLE_MANAGER_ACHIZITII  => 'Manager Achiziții',
            self::ROLE_CONSULTANT_VANZARI => 'Consultant Vânzări',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $user): void {
            if ($user->is_super_admin) {
                $user->is_admin = true;
            }

            if (! $user->location_id) {
                return;
            }

            $location = Location::query()->select(['id', 'type', 'store_id'])->find($user->location_id);

            if ($location?->type === Location::TYPE_WAREHOUSE) {
                $user->location_id = $location->store_id;
            }
        });
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    public function samedayAwbs(): HasMany
    {
        return $this->hasMany(SamedayAwb::class);
    }

    public function isSuperAdmin(): bool
    {
        return (bool) $this->is_super_admin;
    }

    public function isAdmin(): bool
    {
        return $this->isSuperAdmin() || (bool) $this->is_admin;
    }

    public function isOperational(): bool
    {
        return ! $this->isAdmin();
    }

    /**
     * Store access automatically includes warehouses under that store.
     *
     * @return array<int, int>
     */
    public function operationalLocationIds(): array
    {
        if (! $this->location_id) {
            return [];
        }

        return Location::query()
            ->where('id', $this->location_id)
            ->orWhere('store_id', $this->location_id)
            ->pluck('id')
            ->all();
    }

    public function canAccessPanel(Panel $panel): bool
    {
        $isSuperAdmin = $this->isSuperAdmin();
        $isAdminUser = $this->isAdmin();

        return match ($panel->getId()) {
            'admin' => $isAdminUser,
            'app' => $isSuperAdmin || ! $isAdminUser,
            default => false,
        };
    }
}
