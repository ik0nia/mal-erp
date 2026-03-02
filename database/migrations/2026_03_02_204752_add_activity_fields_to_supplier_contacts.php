<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_contacts', function (Blueprint $table) {
            // Sursa contactului: manual = adăugat de utilizator, discovered = detectat din emailuri
            $table->string('source')->default('manual')->after('is_primary');

            // Statistici din istoricul emailurilor
            $table->timestamp('first_seen_at')->nullable()->after('source');
            $table->timestamp('last_seen_at')->nullable()->after('first_seen_at');
            $table->unsignedInteger('email_count')->default(0)->after('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_contacts', function (Blueprint $table) {
            $table->dropColumn(['source', 'first_seen_at', 'last_seen_at', 'email_count']);
        });
    }
};
