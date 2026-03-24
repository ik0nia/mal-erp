<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_style_profiles', function (Blueprint $table) {
            $table->text('visual_style')->nullable()->after('caption_structure');
        });
    }

    public function down(): void
    {
        Schema::table('social_style_profiles', function (Blueprint $table) {
            $table->dropColumn('visual_style');
        });
    }
};
