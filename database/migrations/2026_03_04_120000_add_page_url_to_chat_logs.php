<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_logs', function (Blueprint $table) {
            $table->string('page_url', 500)->nullable()->after('has_products');
            $table->string('page_title', 200)->nullable()->after('page_url');
        });
    }

    public function down(): void
    {
        Schema::table('chat_logs', function (Blueprint $table) {
            $table->dropColumn(['page_url', 'page_title']);
        });
    }
};
