<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_logs', function (Blueprint $table) {
            $table->unsignedInteger('input_tokens')->nullable()->after('has_products');
            $table->unsignedInteger('output_tokens')->nullable()->after('input_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('chat_logs', function (Blueprint $table) {
            $table->dropColumn(['input_tokens', 'output_tokens']);
        });
    }
};
