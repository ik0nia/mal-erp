<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // generated_by nullable — rapoartele generate de cron nu au user
        DB::statement('ALTER TABLE bi_analyses MODIFY generated_by BIGINT UNSIGNED NULL');

        // tip raport: manual | weekly | monthly
        DB::statement("ALTER TABLE bi_analyses ADD COLUMN `type` VARCHAR(20) NOT NULL DEFAULT 'manual' AFTER `id`");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE bi_analyses DROP COLUMN `type`');
        DB::statement('ALTER TABLE bi_analyses MODIFY generated_by BIGINT UNSIGNED NOT NULL');
    }
};
