<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marcarea rambursării către client (când comanda plătită card e modificată în minus).
 * Suma încasată efectivă = paid_total − refund_amount.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('woo_orders', function (Blueprint $table): void {
            $table->decimal('refund_amount', 12, 2)->nullable()->after('diff_payment_at');
            $table->timestamp('refunded_at')->nullable()->after('refund_amount');
            $table->string('refunded_by')->nullable()->after('refunded_at');
        });
    }

    public function down(): void
    {
        Schema::table('woo_orders', function (Blueprint $table): void {
            $table->dropColumn(['refund_amount', 'refunded_at', 'refunded_by']);
        });
    }
};
