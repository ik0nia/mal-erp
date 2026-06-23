<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offer_items', function (Blueprint $table): void {
            $table->decimal('vat_rate', 5, 2)->default(19)->after('discount_percent');
            // Plafonul de discount aplicabil userului pentru acest produs, la momentul ofertei (audit).
            $table->decimal('max_discount_allowed', 5, 2)->nullable()->after('vat_rate');
            $table->boolean('needs_approval')->default(false)->after('max_discount_allowed');
        });
    }

    public function down(): void
    {
        Schema::table('offer_items', function (Blueprint $table): void {
            $table->dropColumn(['vat_rate', 'max_discount_allowed', 'needs_approval']);
        });
    }
};
