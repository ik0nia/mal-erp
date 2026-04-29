<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_suppliers', function (Blueprint $table) {
            // Ultima dată când produsul a fost alertat pentru marjă mică.
            // Deduplicare: nu re-alertăm dacă last_purchase_date <= margin_alerted_at.
            $table->timestamp('margin_alerted_at')->nullable()->after('last_purchase_price');
        });
    }

    public function down(): void
    {
        Schema::table('product_suppliers', function (Blueprint $table) {
            $table->dropColumn('margin_alerted_at');
        });
    }
};
