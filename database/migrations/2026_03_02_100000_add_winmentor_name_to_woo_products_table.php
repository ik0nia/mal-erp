<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('woo_products', function (Blueprint $table): void {
            $table->string('winmentor_name')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('woo_products', function (Blueprint $table): void {
            $table->dropColumn('winmentor_name');
        });
    }
};
