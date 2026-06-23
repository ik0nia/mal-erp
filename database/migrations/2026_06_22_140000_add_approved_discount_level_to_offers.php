<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table): void {
            // Nivelul de discount aprobat de manager — dacă vânzătorul îl depășește ulterior,
            // aprobarea se invalidează automat (re-aprobare necesară).
            $table->decimal('approved_discount_level', 5, 2)->nullable()->after('approval_notified_at');
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table): void {
            $table->dropColumn('approved_discount_level');
        });
    }
};
