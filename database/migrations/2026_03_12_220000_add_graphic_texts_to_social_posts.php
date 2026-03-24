<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_posts', function (Blueprint $table) {
            $table->string('graphic_title', 120)->nullable()->after('image_prompt');
            $table->string('graphic_subtitle', 160)->nullable()->after('graphic_title');
            $table->string('graphic_label', 80)->nullable()->after('graphic_subtitle');
        });
    }

    public function down(): void
    {
        Schema::table('social_posts', function (Blueprint $table) {
            $table->dropColumn(['graphic_title', 'graphic_subtitle', 'graphic_label']);
        });
    }
};
