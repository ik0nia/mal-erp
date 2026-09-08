<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sameday_lockers', function (Blueprint $table) {
            $table->decimal('lat', 10, 7)->nullable()->after('postal_code');
            $table->decimal('lng', 10, 7)->nullable()->after('lat');
        });
    }

    public function down(): void
    {
        Schema::table('sameday_lockers', function (Blueprint $table) {
            $table->dropColumn(['lat', 'lng']);
        });
    }
};
