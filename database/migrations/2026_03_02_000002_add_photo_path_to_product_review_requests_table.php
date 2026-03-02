<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_review_requests', function (Blueprint $table): void {
            $table->string('photo_path')->nullable()->after('message');
        });
    }

    public function down(): void
    {
        Schema::table('product_review_requests', function (Blueprint $table): void {
            $table->dropColumn('photo_path');
        });
    }
};
