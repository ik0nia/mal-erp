<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Legătură STABILĂ cu partenerul WinMentor (wm_id intern) — winmentor_id
        // ținea CUI-ul (convenție veche, de nefolosit pentru PF fără CUI).
        Schema::table('customers', function (Blueprint $t) {
            $t->string('winmentor_partner_id', 50)->nullable()->after('winmentor_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $t) {
            $t->dropColumn('winmentor_partner_id');
        });
    }
};
