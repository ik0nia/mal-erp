<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->unsignedSmallInteger('invoice_position')->nullable()->after('received_note')
                ->comment('Poziția pe factura furnizorului (pentru trimitere ordonată la WinMentor)');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropColumn('invoice_position');
        });
    }
};
