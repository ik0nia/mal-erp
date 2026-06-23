<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['sameday_awbs', 'woo_orders', 'email_messages'] as $t) {
            Schema::table($t, function (Blueprint $table) use ($t) {
                if (! Schema::hasColumn($t, 'anonymized_at')) {
                    $table->timestamp('anonymized_at')->nullable()->index();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['sameday_awbs', 'woo_orders', 'email_messages'] as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->dropColumn('anonymized_at');
            });
        }
    }
};
