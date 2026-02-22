<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table): void {
            $table->string('company_postal_code')->nullable()->after('company_registration_number');
            $table->string('company_phone')->nullable()->after('company_postal_code');
            $table->string('company_bank')->nullable()->after('company_phone');
            $table->string('company_bank_account')->nullable()->after('company_bank');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table): void {
            $table->dropColumn([
                'company_postal_code',
                'company_phone',
                'company_bank',
                'company_bank_account',
            ]);
        });
    }
};
