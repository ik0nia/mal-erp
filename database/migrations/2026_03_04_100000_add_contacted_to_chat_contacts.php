<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_contacts', function (Blueprint $table) {
            $table->timestamp('contacted_at')->nullable()->after('wants_specialist');
            $table->foreignId('contacted_by')->nullable()->after('contacted_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('chat_contacts', function (Blueprint $table) {
            $table->dropForeign(['contacted_by']);
            $table->dropColumn(['contacted_at', 'contacted_by']);
        });
    }
};
