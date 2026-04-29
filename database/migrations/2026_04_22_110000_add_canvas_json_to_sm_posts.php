<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sm_posts', function (Blueprint $table) {
            $table->json('canvas_json')->nullable()->after('graphic_texts');
        });
    }

    public function down(): void
    {
        Schema::table('sm_posts', function (Blueprint $table) {
            $table->dropColumn('canvas_json');
        });
    }
};
