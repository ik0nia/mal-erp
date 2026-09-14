<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Index pe cod_fiscal_client — fișa client filtrează masiv vanzari_raw (1M+ rânduri)
 * după CUI (OR cu part_id). Fără el = full scan → fișa se bloca (>2 min).
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = collect(DB::select("SHOW INDEX FROM winmentor_vanzari_raw"))
            ->contains(fn ($i) => $i->Key_name === 'vanzari_cod_fiscal_idx');
        if (! $exists) {
            Schema::table('winmentor_vanzari_raw', fn ($t) => $t->index('cod_fiscal_client', 'vanzari_cod_fiscal_idx'));
        }
    }

    public function down(): void
    {
        Schema::table('winmentor_vanzari_raw', fn ($t) => $t->dropIndex('vanzari_cod_fiscal_idx'));
    }
};
