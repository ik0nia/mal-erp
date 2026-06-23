<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('breach_incidents', function (Blueprint $table) {
            if (! Schema::hasColumn('breach_incidents', 'type')) {
                $table->string('type', 24)->default('data_breach')->after('id'); // data_breach | security_alert
            }
        });
    }

    public function down(): void
    {
        Schema::table('breach_incidents', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
