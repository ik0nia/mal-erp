<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sameday_awbs', function (Blueprint $table) {
            // extrase din tracking: momentul ridicării și al livrării → durata de livrare
            $table->timestamp('picked_up_at')->nullable()->after('courier_status_at');
            $table->timestamp('delivered_at')->nullable()->after('picked_up_at');
        });
    }

    public function down(): void
    {
        Schema::table('sameday_awbs', function (Blueprint $table) {
            $table->dropColumn(['picked_up_at', 'delivered_at']);
        });
    }
};
