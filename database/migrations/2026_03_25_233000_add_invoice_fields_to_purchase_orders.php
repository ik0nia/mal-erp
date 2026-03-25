<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->string('invoice_series')->nullable()->after('received_notes');
            $table->string('invoice_number')->nullable()->after('invoice_series');
            $table->date('invoice_date')->nullable()->after('invoice_number');
            $table->date('invoice_due_date')->nullable()->after('invoice_date');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn(['invoice_series', 'invoice_number', 'invoice_date', 'invoice_due_date']);
        });
    }
};
