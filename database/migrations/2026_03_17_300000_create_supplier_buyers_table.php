<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_buyers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unique(['supplier_id', 'user_id']);
        });

        // Migrăm datele existente din buyer_id
        DB::table('suppliers')
            ->whereNotNull('buyer_id')
            ->get(['id', 'buyer_id'])
            ->each(fn ($s) => DB::table('supplier_buyers')->insertOrIgnore([
                'supplier_id' => $s->id,
                'user_id'     => $s->buyer_id,
            ]));
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_buyers');
    }
};
