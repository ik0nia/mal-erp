<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->decimal('default_markup', 5, 2)->nullable()->after('po_approval_threshold')
                ->comment('Adaos comercial implicit (%) pentru recalculare prețuri vânzare');
            $table->decimal('default_vat', 5, 2)->nullable()->after('default_markup')
                ->comment('TVA implicit (%) pentru recalculare prețuri vânzare');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn(['default_markup', 'default_vat']);
        });
    }
};
