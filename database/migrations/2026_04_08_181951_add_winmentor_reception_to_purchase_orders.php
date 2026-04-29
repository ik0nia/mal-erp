<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            // Numărul documentului de intrare din WinMentor (NIR/recepție contabilă)
            $table->string('winmentor_receptie_nr', 100)->nullable()->after('winmentor_synced_at');
            // Data înregistrării recepției în WinMentor
            $table->date('winmentor_receptie_date')->nullable()->after('winmentor_receptie_nr');
            // Scorul de potrivire (0-100) — câte % din SKU-urile PO au fost găsite în documentul WinMentor
            $table->unsignedTinyInteger('winmentor_receptie_score')->nullable()->after('winmentor_receptie_date');
            // Când s-a rulat ultima dată asocierea pentru acest PO
            $table->timestamp('winmentor_receptie_matched_at')->nullable()->after('winmentor_receptie_score');
            // Lead time: zile de la trimiterea PO până la recepția cantitativă (received_at)
            $table->unsignedSmallInteger('lead_time_days')->nullable()->after('winmentor_receptie_matched_at');
            // Zile de la recepția cantitativă până la recepția contabilă în WinMentor
            $table->unsignedSmallInteger('receptie_contabila_lag_days')->nullable()->after('lead_time_days');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn([
                'winmentor_receptie_nr',
                'winmentor_receptie_date',
                'winmentor_receptie_score',
                'winmentor_receptie_matched_at',
            ]);
        });
    }
};
