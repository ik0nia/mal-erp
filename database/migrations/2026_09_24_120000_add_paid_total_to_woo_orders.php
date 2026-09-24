<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suma efectiv ÎNCASATĂ la momentul plății (snapshot), separată de `total`.
 * `total` se schimbă când comanda e modificată; `paid_total` rămâne cât s-a
 * încasat, ca să putem evidenția diferența (de rambursat / de încasat în plus).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('woo_orders', function (Blueprint $table): void {
            $table->decimal('paid_total', 12, 2)->nullable()->after('total');
        });
    }

    public function down(): void
    {
        Schema::table('woo_orders', function (Blueprint $table): void {
            $table->dropColumn('paid_total');
        });
    }
};
