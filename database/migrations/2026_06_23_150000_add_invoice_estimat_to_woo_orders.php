<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('woo_orders', function (Blueprint $table) {
            $table->boolean('winmentor_invoice_estimat')->default(false)->after('winmentor_invoice_total');
        });
    }

    public function down(): void
    {
        Schema::table('woo_orders', function (Blueprint $table) {
            $table->dropColumn('winmentor_invoice_estimat');
        });
    }
};
