<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offer_items', function (Blueprint $table): void {
            $table->string('unit', 20)->default('buc')->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('offer_items', function (Blueprint $table): void {
            $table->dropColumn('unit');
        });
    }
};
