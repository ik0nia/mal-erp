<?php

use App\Models\AppSetting;
use App\Models\Offer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Setarea globală implicită pentru cota TVA în oferte.
        if (! AppSetting::query()->where('key', AppSetting::KEY_OFFER_VAT_RATE)->exists()) {
            AppSetting::set(AppSetting::KEY_OFFER_VAT_RATE, '21');
        }

        // Schimbă default-ul coloanei la 21.
        DB::statement('ALTER TABLE offer_items ALTER COLUMN vat_rate SET DEFAULT 21.00');

        // Catalogul avea 19 (vechi) — aducem ofertele la cota curentă 21 și recalculăm.
        DB::table('offer_items')->where('vat_rate', 19)->update(['vat_rate' => 21]);

        Offer::with('items')->get()->each->recalculateTotals();
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE offer_items ALTER COLUMN vat_rate SET DEFAULT 19.00');
    }
};
