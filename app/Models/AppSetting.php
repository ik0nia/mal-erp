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

    // Date firmă
    const KEY_COMPANY_NAME       = 'company_name';
    const KEY_COMPANY_CUI        = 'company_cui';
    const KEY_COMPANY_REG_COM    = 'company_reg_com';
    const KEY_COMPANY_ADDRESS    = 'company_address';
    const KEY_COMPANY_EMAIL      = 'company_email';
    const KEY_COMPANY_PHONE      = 'company_phone';

    const KEY_PNR_SERIES         = 'pnr_series';
    const KEY_PNR_START_NUMBER   = 'pnr_start_number';
    const KEY_OFFER_SERIES       = 'offer_series';
    const KEY_OFFER_START_NUMBER = 'offer_start_number';

    // IMAP — inbox read-only
    const KEY_IMAP_HOST          = 'imap_host';
    const KEY_IMAP_PORT          = 'imap_port';
    const KEY_IMAP_ENCRYPTION    = 'imap_encryption';
    const KEY_IMAP_USERNAME      = 'imap_username';
    const KEY_IMAP_PASSWORD      = 'imap_password'; // stocat criptat

    // AI — procesare emailuri
    const KEY_ANTHROPIC_API_KEY  = 'anthropic_api_key'; // stocat criptat

    // Chat widget public
    const KEY_CHAT_PRIMARY_COLOR        = 'chat_primary_color';
    const KEY_CHAT_BOT_NAME             = 'chat_bot_name';
    const KEY_CHAT_SUBTITLE             = 'chat_subtitle';
    const KEY_CHAT_WELCOME_MSG          = 'chat_welcome_message';
    const KEY_CHAT_ENABLED              = 'chat_enabled';
    const KEY_CHAT_MAX_COST_PER_SESSION = 'chat_max_cost_per_session'; // USD, ex: "0.05"

    // Telegram notificări lead-uri
    const KEY_TELEGRAM_BOT_TOKEN = 'telegram_bot_token'; // stocat criptat
    const KEY_TELEGRAM_CHAT_ID   = 'telegram_chat_id';

    // Plugin WordPress ERP Bridge
    const KEY_WOO_PLUGIN_API_KEY = 'woo_plugin_api_key'; // stocat criptat — cheia trimisă în X-ERP-Api-Key

    // Toya Pimcore API (cheia globală — fallback dacă nu e setată pe SupplierFeed)
    const KEY_TOYA_API_KEY = 'toya_api_key'; // stocat criptat

    // Social Media
    const KEY_GEMINI_API_KEY      = 'gemini_api_key';      // stocat criptat
    const KEY_META_APP_ID         = 'meta_app_id';
    const KEY_META_APP_SECRET     = 'meta_app_secret';     // stocat criptat

    public static function getEncrypted(string $key): ?string
    {
        $value = static::get($key);
        if (blank($value)) {
            // Fallback la variabilă de mediu (ex: anthropic_api_key → ANTHROPIC_API_KEY)
            $envValue = env(strtoupper($key));
            return filled($envValue) ? (string) $envValue : null;
        }
        try {
            return decrypt($value);
        } catch (\Throwable) {
            return $value; // fallback dacă nu e criptat
        }
    }

    public static function setEncrypted(string $key, ?string $value): void
    {
        static::set($key, filled($value) ? encrypt($value) : null);
    }

    // Grupuri navigare App panel — cheie AppSetting => [label exact din resurse, sort implicit]
    const NAV_GROUPS = [
        'nav_group_sort_administrare_magazin' => ['label' => 'Administrare magazin', 'default' => 1],
        'nav_group_sort_achizitii'            => ['label' => 'Achiziții',            'default' => 2],
        'nav_group_sort_vanzari'              => ['label' => 'Vânzări',              'default' => 3],
        'nav_group_sort_comenzi'              => ['label' => 'Comenzi',              'default' => 4],
        'nav_group_sort_livrare'              => ['label' => 'Livrare',              'default' => 5],
        'nav_group_sort_rapoarte'             => ['label' => 'Rapoarte',             'default' => 6],
        'nav_group_sort_produse'              => ['label' => 'Produse',              'default' => 7],
        'nav_group_sort_comunicare'           => ['label' => 'Comunicare',           'default' => 8],
        'nav_group_sort_social_media'         => ['label' => 'Social Media',         'default' => 9],
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
        // Comunicare
        'nav_item_sort_App_Filament_App_Pages_EmailInboxPage'                    => ['label' => 'Inbox Email',           'group' => 'Comunicare',           'default' => 1],
        'nav_item_sort_App_Filament_App_Pages_EmailCommunicationStatsPage'       => ['label' => 'Statistici',            'group' => 'Comunicare',           'default' => 2],
        // Social Media
        'nav_item_sort_App_Filament_App_Resources_SocialPostResource'            => ['label' => 'Postări',               'group' => 'Social Media',         'default' => 1],
        'nav_item_sort_App_Filament_App_Pages_SocialAccountsPage'                => ['label' => 'Conturi',               'group' => 'Social Media',         'default' => 2],
        'nav_item_sort_App_Filament_App_Resources_GraphicTemplateResource'       => ['label' => 'Template-uri grafice',  'group' => 'Social Media',         'default' => 10],
        'nav_item_sort_App_Filament_App_Pages_GraphicTemplateEditorPage'         => ['label' => 'Editor Template',       'group' => 'Social Media',         'default' => 11],
        'nav_item_sort_App_Filament_App_Pages_GraphicTemplateVisualEditorPage'   => ['label' => 'Editor Vizual',         'group' => 'Social Media',         'default' => 12],
        // Achiziții (suplimentar)
        'nav_item_sort_App_Filament_App_Pages_UnassignedItemsPage'               => ['label' => 'Alocare furnizori',     'group' => 'Achiziții',            'default' => 2],
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
