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
    use \App\Models\Concerns\Auditable;
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
        'warehouse_pin',
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
            'last_login_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }

    /**
     * Statusul de sesiune pe baza ultimei activități (contează și PWA):
     *  - 'online'  = activ în ultimele 10 min
     *  - 'active'  = activ în durata sesiunii (SESSION_LIFETIME) → sesiune validă, dar idle
     *  - 'offline' = fără activitate recentă (delogat / sesiune expirată)
     */
    public function getActivityStatusAttribute(): string
    {
        if (! $this->last_activity_at) {
            return 'offline';
        }
        if ($this->last_activity_at->gt(now()->subMinutes(10))) {
            return 'online';
        }
        $lifetime = (int) config('session.lifetime', 120);
        if ($this->last_activity_at->gt(now()->subMinutes($lifetime))) {
            return 'active';
        }
        return 'offline';
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
            self::ROLE_DIRECTOR_VANZARI   => 'Director Magazin',
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

    public function managedSuppliers(): HasMany
    {
        return $this->hasMany(Supplier::class, 'buyer_id');
    }

    public function purchaseRequests(): HasMany
    {
        return $this->hasMany(PurchaseRequest::class);
    }

    public function isConsultantVanzari(): bool
    {
        return $this->role === self::ROLE_CONSULTANT_VANZARI;
    }

    public function isBuyer(): bool
    {
        return $this->role === self::ROLE_MANAGER_ACHIZITII;
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
            'app' => true,
            default => false,
        };
    }
}
