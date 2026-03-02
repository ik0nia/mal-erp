<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_messages', function (Blueprint $table) {
            // Link direct la contactul specific care a trimis emailul
            $table->foreignId('supplier_contact_id')
                ->nullable()
                ->after('supplier_id')
                ->constrained('supplier_contacts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('email_messages', function (Blueprint $table) {
            $table->dropForeign(['supplier_contact_id']);
            $table->dropColumn('supplier_contact_id');
        });
    }
};
