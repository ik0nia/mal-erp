<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class AppSetting extends Model
{
    protected $primaryKey = 'key';
    protected $keyType    = 'string';
    public    $incrementing = false;

    protected $fillable = ['key', 'value'];

    const KEY_LOGO_PATH          = 'logo_path';
    const KEY_BRAND_NAME         = 'brand_name';
    const KEY_PNR_SERIES         = 'pnr_series';
    const KEY_PNR_START_NUMBER   = 'pnr_start_number';
    const KEY_OFFER_SERIES       = 'offer_series';
    const KEY_OFFER_START_NUMBER = 'offer_start_number';

    // Grupuri navigare App panel — cheie AppSetting => [label exact din resurse, sort implicit]
    const NAV_GROUPS = [
        'nav_group_sort_administrare_magazin' => ['label' => 'Administrare magazin', 'default' => 1],
        'nav_group_sort_achizitii'            => ['label' => 'Achiziții',            'default' => 2],
        'nav_group_sort_vanzari'              => ['label' => 'Vânzări',              'default' => 3],
        'nav_group_sort_comenzi'              => ['label' => 'Comenzi',              'default' => 4],
        'nav_group_sort_livrare'              => ['label' => 'Livrare',              'default' => 5],
        'nav_group_sort_rapoarte'             => ['label' => 'Rapoarte',             'default' => 6],
        'nav_group_sort_produse'              => ['label' => 'Produse',              'default' => 7],
    ];

    // Iteme navigare — cheie AppSetting => [label, grup, sort implicit]
    const NAV_ITEMS = [
        // Administrare magazin
        'nav_item_sort_App_Filament_App_Resources_WooProductResource'            => ['label' => 'Produse',               'group' => 'Administrare magazin', 'default' => 20],
        'nav_item_sort_App_Filament_App_Resources_WooCategoryResource'           => ['label' => 'Categorii',             'group' => 'Administrare magazin', 'default' => 10],
        'nav_item_sort_App_Filament_App_Resources_EanAssociationRequestResource' => ['label' => 'Cereri asociere EAN',   'group' => 'Administrare magazin', 'default' => 99],
        'nav_item_sort_App_Filament_App_Resources_ProductPriceLogResource'       => ['label' => 'Modificări prețuri',    'group' => 'Administrare magazin', 'default' => 50],
        'nav_item_sort_App_Filament_App_Pages_SkuDiscrepancyReport'              => ['label' => 'Discrepanțe SKU',       'group' => 'Administrare magazin', 'default' => 30],
        // Achiziții
        'nav_item_sort_App_Filament_App_Resources_PurchaseRequestResource'       => ['label' => 'Necesare',              'group' => 'Achiziții',            'default' => 1],
        'nav_item_sort_App_Filament_App_Pages_BuyerDashboardPage'                => ['label' => 'Tablou comenzi',        'group' => 'Achiziții',            'default' => 2],
        'nav_item_sort_App_Filament_App_Resources_PurchaseOrderResource'         => ['label' => 'Comenzi furnizori',     'group' => 'Achiziții',            'default' => 3],
        'nav_item_sort_App_Filament_App_Resources_SupplierResource'              => ['label' => 'Furnizori',             'group' => 'Achiziții',            'default' => 4],
        'nav_item_sort_App_Filament_App_Resources_BrandResource'                 => ['label' => 'Branduri',              'group' => 'Achiziții',            'default' => 5],
        'nav_item_sort_App_Filament_App_Pages_ProductsWithoutSupplier'           => ['label' => 'Fără furnizor',         'group' => 'Achiziții',            'default' => 6],
        // Vânzări
        'nav_item_sort_App_Filament_App_Resources_CustomerResource'              => ['label' => 'Clienți',               'group' => 'Vânzări',              'default' => 5],
        'nav_item_sort_App_Filament_App_Resources_OfferResource'                 => ['label' => 'Oferte',                'group' => 'Vânzări',              'default' => 10],
        // Comenzi
        'nav_item_sort_App_Filament_App_Resources_WooOrderResource'              => ['label' => 'Comenzi Online',        'group' => 'Comenzi',              'default' => 10],
        'nav_item_sort_App_Filament_App_Pages_ComenziMagazin'                    => ['label' => 'Comenzi Magazin',       'group' => 'Comenzi',              'default' => 20],
        // Livrare
        'nav_item_sort_App_Filament_App_Resources_SamedayAwbResource'            => ['label' => 'AWB Sameday',           'group' => 'Livrare',              'default' => 1],
        // Rapoarte
        'nav_item_sort_App_Filament_App_Pages_NecesarMarfa'                      => ['label' => 'Necesar de marfă',      'group' => 'Rapoarte',             'default' => 0],
        'nav_item_sort_App_Filament_App_Pages_StockMovementsReport'              => ['label' => 'Mișcări stocuri',       'group' => 'Rapoarte',             'default' => 10],
        'nav_item_sort_App_Filament_App_Pages_OnlineShopReport'                  => ['label' => 'Raport Magazin Online', 'group' => 'Rapoarte',             'default' => 20],
        'nav_item_sort_App_Filament_App_Pages_BiDashboardPage'                   => ['label' => 'Dashboard BI',          'group' => 'Rapoarte',             'default' => 89],
        'nav_item_sort_App_Filament_App_Pages_BiAnalysisPage'                    => ['label' => 'Analiză BI',            'group' => 'Rapoarte',             'default' => 99],
        // Produse
        'nav_item_sort_App_Filament_App_Pages_NewWinmentorProducts'              => ['label' => 'Produse noi WinMentor', 'group' => 'Produse',              'default' => 25],
        'nav_item_sort_App_Filament_App_Pages_ProductReviewRequestsPage'         => ['label' => 'Reverificări produse',  'group' => 'Produse',              'default' => 30],
    ];

    public static function get(string $key, ?string $default = null): ?string
    {
        try {
            return Cache::remember("app_setting:{$key}", 300, function () use ($key, $default) {
                return static::find($key)?->value ?? $default;
            });
        } catch (\Throwable $e) {
            // Cache unavailable (e.g. permission conflict între erp/www-data) — fallback direct la DB
            try {
                return static::find($key)?->value ?? $default;
            } catch (\Throwable $e2) {
                return $default;
            }
        }
    }

    public static function set(string $key, ?string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget("app_setting:{$key}");
    }
}
