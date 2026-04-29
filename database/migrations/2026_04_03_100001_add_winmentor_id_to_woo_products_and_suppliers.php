<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('woo_products', function (Blueprint $table) {
            $table->string('winmentor_id')->nullable()->after('winmentor_name')->comment('codIntern din WinMentor — cheia de legatura permanenta');
            $table->index('winmentor_id');
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('winmentor_id')->nullable()->after('vat_number')->comment('idPartener din WinMentor Bridge');
        });
    }

    public function down(): void
    {
        Schema::table('woo_products', function (Blueprint $table) {
            $table->dropIndex(['winmentor_id']);
            $table->dropColumn('winmentor_id');
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn('winmentor_id');
        });
    }
};
