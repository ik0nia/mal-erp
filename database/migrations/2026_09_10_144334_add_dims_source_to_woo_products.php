<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('woo_products', function (Blueprint $table) {
            // proveniența dimensiunilor/greutății: null = date originale (furnizor/manual),
            // 'extracted' = extrase din titlu/descriere, 'estimated' = estimare AI
            $table->string('dims_source', 12)->nullable()->after('dim_height');
        });
    }

    public function down(): void
    {
        Schema::table('woo_products', function (Blueprint $table) {
            $table->dropColumn('dims_source');
        });
    }
};
