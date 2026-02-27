<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bi_analyses', function (Blueprint $table) {
            $table->string('status')->default('pending')->after('generated_by'); // pending | done | failed
            $table->text('error_message')->nullable()->after('metrics_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('bi_analyses', function (Blueprint $table) {
            $table->dropColumn(['status', 'error_message']);
        });
    }
};
