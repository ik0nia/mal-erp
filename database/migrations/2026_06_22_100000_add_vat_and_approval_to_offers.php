<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table): void {
            // TVA — prețurile produselor includ TVA, defalcăm baza și TVA-ul.
            $table->decimal('subtotal_without_vat', 14, 4)->default(0)->after('discount_total');
            $table->decimal('vat_total', 14, 4)->default(0)->after('subtotal_without_vat');

            // Aprobare discount (separat de statusul față de client).
            // not_required = totul în plafon auto; pending = depășește plafonul auto; approved/rejected = decizie manager.
            $table->string('approval_status', 20)->default('not_required')->after('status');
            $table->foreignId('approved_by')->nullable()->after('approval_status')->constrained('users')->nullOnDelete();
            $table->timestamp('approval_requested_at')->nullable()->after('approved_by');
            $table->timestamp('approved_at')->nullable()->after('approval_requested_at');
            $table->text('approval_note')->nullable()->after('approved_at');

            $table->index('approval_status');
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn([
                'subtotal_without_vat',
                'vat_total',
                'approval_status',
                'approval_requested_at',
                'approved_at',
                'approval_note',
            ]);
        });
    }
};
