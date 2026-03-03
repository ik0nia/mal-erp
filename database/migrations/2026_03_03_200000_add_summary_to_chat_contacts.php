<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_contacts', function (Blueprint $table) {
            $table->text('summary')->nullable()->after('wants_specialist');
            $table->string('interested_in', 500)->nullable()->after('summary');
        });
    }

    public function down(): void
    {
        Schema::table('chat_contacts', function (Blueprint $table) {
            $table->dropColumn(['summary', 'interested_in']);
        });
    }
};
