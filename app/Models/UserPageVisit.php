<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * O accesare de pagină de către un utilizator (jurnal de navigare cronologic).
 * Alimentat de middleware-ul UpdateLastActivity::logPageVisit().
 */
class UserPageVisit extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'method', 'path', 'route_name', 'title', 'visited_at'];

    protected function casts(): array
    {
        return ['visited_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Slug resursă Filament → model, pentru a rezolva înregistrarea concretă din URL. */
    protected const RESOURCE_MODELS = [
        'produse'           => WooProduct::class,
        'woo-products'      => WooProduct::class,
        'woo-orders'        => WooOrder::class,
        'customers'         => Customer::class,
        'suppliers'         => Supplier::class,
        'purchase-orders'   => PurchaseOrder::class,
        'purchase-requests' => PurchaseRequest::class,
        'sameday-awbs'      => SamedayAwb::class,
        'users'             => User::class,
        'offers'            => Offer::class,
    ];

    public function getLabelAttribute(): string
    {
        return static::labelFor($this->route_name, ltrim((string) $this->path, '/'));
    }

    /** Numele concret al înregistrării vizitate (ex. „#157199", „Produs X"), dacă e o pagină de tip view/edit. */
    public function getDetailAttribute(): ?string
    {
        return static::resolveRecord($this->route_name, (string) $this->path);
    }

    public static function resolveRecord(?string $routeName, ?string $path): ?string
    {
        if (! $routeName || ! $path || ! str_contains($routeName, '.resources.')) {
            return null;
        }
        // Doar paginile legate de o înregistrare (view/edit) au id în URL.
        if (! preg_match('/\.(view|edit)$/', $routeName)) {
            return null;
        }

        $parts = explode('.', $routeName);
        $slug  = $parts[array_search('resources', $parts, true) + 1] ?? null;
        if (! $slug || ! isset(self::RESOURCE_MODELS[$slug])) {
            return null;
        }
        if (! preg_match('#(?:^|/)(\d+)(?:/edit)?$#', $path, $m)) {
            return null;
        }

        try {
            $rec = self::RESOURCE_MODELS[$slug]::query()->find((int) $m[1]);
        } catch (\Throwable) {
            return null;
        }
        if (! $rec) {
            return null;
        }

        foreach (['number', 'awb_number', 'decoded_name', 'name', 'title', 'sku', 'email'] as $f) {
            $val = $rec->{$f} ?? null;
            if (! empty($val)) {
                return in_array($f, ['number', 'awb_number'], true) ? '#'.$val : (string) $val;
            }
        }

        return '#'.$m[1];
    }

    /** Etichetă lizibilă dintr-un route_name Filament / cale. */
    public static function labelFor(?string $routeName, string $path): string
    {
        if ($routeName && str_contains($routeName, '.resources.')) {
            $parts = explode('.', $routeName);
            $res   = $parts[array_search('resources', $parts, true) + 1] ?? null;
            $page  = end($parts);
            if ($res) {
                return ucfirst(str_replace('-', ' ', $res)).($page && $page !== 'index' ? ' · '.$page : '');
            }
        }
        if ($routeName && str_contains($routeName, '.pages.')) {
            $parts = explode('.', $routeName);

            return 'Pagină · '.ucfirst(str_replace('-', ' ', (string) end($parts)));
        }

        return '/'.$path;
    }
}
