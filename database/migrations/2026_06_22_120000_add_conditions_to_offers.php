<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table): void {
            $table->string('discount_condition', 20)->default('per_line')->after('notes');
            $table->string('transport_mode', 20)->default('not_included')->after('discount_condition');
            $table->decimal('transport_free_over', 14, 2)->nullable()->after('transport_mode');
            $table->string('payment_terms')->nullable()->after('transport_free_over');
            $table->string('delivery_terms')->nullable()->after('payment_terms');
            $table->text('extra_terms')->nullable()->after('delivery_terms');
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table): void {
            $table->dropColumn([
                'discount_condition',
                'transport_mode',
                'transport_free_over',
                'payment_terms',
                'delivery_terms',
                'extra_terms',
            ]);
        });
    }
};
