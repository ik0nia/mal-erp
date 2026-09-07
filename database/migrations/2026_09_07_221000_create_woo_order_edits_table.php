<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Istoric modificări comenzi WooCommerce făcute din ERP, cu snapshot
        // pentru revenire (undo): ștergeri/editări/adăugări de produse, adresă, transport.
        Schema::create('woo_order_edits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('woo_order_id')->constrained('woo_orders')->cascadeOnDelete();
            $t->string('user_email')->nullable();
            $t->string('action', 30);   // remove_item | add_item | edit_items | edit_address
            $t->string('label', 255);   // descriere umană: „Șters: Adeziv X ×3"
            $t->json('before')->nullable();
            $t->json('after')->nullable();
            $t->timestamp('reverted_at')->nullable();
            $t->string('reverted_by')->nullable();
            $t->timestamps();
            $t->index(['woo_order_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('woo_order_edits');
    }
};
