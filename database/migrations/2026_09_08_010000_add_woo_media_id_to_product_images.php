<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ID-ul atașamentului din media library WooCommerce — la sync trimitem {id}
        // pentru imaginile deja urcate (altfel Woo re-sideload-ează la fiecare
        // reordonare și umple media library cu duplicate).
        Schema::table('product_images', function (Blueprint $t) {
            $t->unsignedBigInteger('woo_media_id')->nullable()->after('url');
        });
    }

    public function down(): void
    {
        Schema::table('product_images', function (Blueprint $t) {
            $t->dropColumn('woo_media_id');
        });
    }
};
