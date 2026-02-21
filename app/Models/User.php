<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        ];
    }

    public const ROLE_MANAGER = 'manager';
    public const ROLE_ACCOUNTANT = 'accountant';
    public const ROLE_SUPERVISOR = 'supervisor';
    public const ROLE_OPERATOR = 'operator';

    public static function roleOptions(): array
    {
        return [
            self::ROLE_MANAGER => 'Manager',
            self::ROLE_ACCOUNTANT => 'Contabil',
            self::ROLE_SUPERVISOR => 'Supervizor',
            self::ROLE_OPERATOR => 'Operator',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $user): void {
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
        $isSuperAdmin = strtolower((string) $this->email) === 'codrut@ikonia.ro';
        $isAdminUser = $isSuperAdmin || $this->location_id === null;

        return match ($panel->getId()) {
            'admin' => $isAdminUser,
            'app' => ! $isSuperAdmin && ! $isAdminUser,
            default => false,
        };
    }
}
