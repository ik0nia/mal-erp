<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Grup de clienți: mai multe fișe (firme diferite + persoană fizică) ale aceluiași
 * client real, legate manual, cu istoric agregat. Membrii unui grup au același
 * customer_group_id (convenția: id-ul cel mai mic din grup). null = negrupat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->unsignedBigInteger('customer_group_id')->nullable()->index()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('customer_group_id');
        });
    }
};
