<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_posts', function (Blueprint $table) {
            $table->foreignId('brand_id')->nullable()->after('woo_product_id')->constrained('brands')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('social_posts', function (Blueprint $table) {
            $table->dropForeignIdFor(\App\Models\Brand::class);
            $table->dropColumn('brand_id');
        });
    }
};
