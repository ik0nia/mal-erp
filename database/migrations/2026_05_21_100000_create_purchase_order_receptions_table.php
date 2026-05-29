<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_receptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('reception_number')->default(1);
            $table->boolean('is_final')->default(false);
            $table->dateTime('received_at');
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('received_notes')->nullable();

            // WinMentor sync per recepție
            $table->string('winmentor_sync_status', 20)->default('pending');
            $table->text('winmentor_sync_error')->nullable();
            $table->string('winmentor_order_nr', 50)->nullable();
            $table->dateTime('winmentor_synced_at')->nullable();

            $table->timestamps();

            $table->index(['purchase_order_id', 'reception_number'], 'po_recv_po_nr_idx');
        });

        Schema::create('purchase_order_reception_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('reception_id');
            $table->unsignedBigInteger('order_item_id');
            $table->decimal('received_quantity', 10, 3)->default(0);
            $table->string('received_note', 100)->nullable();
            $table->unsignedSmallInteger('invoice_position')->nullable();
            $table->timestamps();

            $table->foreign('reception_id', 'po_recv_items_recv_fk')
                  ->references('id')->on('purchase_order_receptions')->cascadeOnDelete();
            $table->foreign('order_item_id', 'po_recv_items_item_fk')
                  ->references('id')->on('purchase_order_items')->cascadeOnDelete();
            $table->index('order_item_id', 'po_recv_items_item_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_reception_items');
        Schema::dropIfExists('purchase_order_receptions');
    }
};
