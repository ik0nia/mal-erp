<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sameday_awbs', function (Blueprint $table) {
            // câte apeluri de tracking au eșuat consecutiv — renunțăm (indisponibil)
            // abia după mai multe încercări în zile diferite, nu la primul eșec
            $table->unsignedTinyInteger('tracking_attempts')->default(0)->after('courier_status_at');
        });
    }

    public function down(): void
    {
        Schema::table('sameday_awbs', function (Blueprint $table) {
            $table->dropColumn('tracking_attempts');
        });
    }
};
