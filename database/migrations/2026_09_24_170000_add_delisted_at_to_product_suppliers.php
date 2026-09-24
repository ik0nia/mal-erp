<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marchează că un produs a fost DELISTAT de furnizor (codul a dispărut din feed/catalog).
 * Setat de toya:draft-discontinued; se golește când produsul reapare la furnizor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_suppliers', function (Blueprint $table): void {
            $table->timestamp('delisted_at')->nullable()->after('supplier_sku');
        });
    }

    public function down(): void
    {
        Schema::table('product_suppliers', function (Blueprint $table): void {
            $table->dropColumn('delisted_at');
        });
    }
};
