<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Model „alocări pe cantitate": o linie poate fi împărțită pe mai multe surse
     * (magazin/depozit/livrare), fiecare cu cantitatea ei + predare parțială (cant_predata).
     * Cheie unică: (tip_doc, doc_id, pozitie, sursa) — cu prefixe ca să nu depășească
     * limita de lungime a indexului InnoDB.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('dispecerizari_vanzari', 'cant_alocata')) {
            Schema::table('dispecerizari_vanzari', function (Blueprint $table) {
                $table->decimal('cant_alocata', 12, 4)->default(0)->after('sursa');
            });
        }
        if (! Schema::hasColumn('dispecerizari_vanzari', 'cant_predata')) {
            Schema::table('dispecerizari_vanzari', function (Blueprint $table) {
                $table->decimal('cant_predata', 12, 4)->default(0)->after('status');
            });
        }

        if ($this->indexExists('dispecerizari_doc_unique')) {
            DB::statement('ALTER TABLE dispecerizari_vanzari DROP INDEX dispecerizari_doc_unique');
        }
        if (! $this->indexExists('dispecerizari_alocare_unique')) {
            DB::statement('ALTER TABLE dispecerizari_vanzari ADD UNIQUE dispecerizari_alocare_unique (tip_doc(10), doc_id(40), pozitie(40), sursa(20))');
        }
    }

    public function down(): void
    {
        if ($this->indexExists('dispecerizari_alocare_unique')) {
            DB::statement('ALTER TABLE dispecerizari_vanzari DROP INDEX dispecerizari_alocare_unique');
        }
        Schema::table('dispecerizari_vanzari', function (Blueprint $table) {
            $table->dropColumn(['cant_alocata', 'cant_predata']);
        });
    }

    private function indexExists(string $name): bool
    {
        return count(DB::select(
            "SHOW INDEX FROM dispecerizari_vanzari WHERE Key_name = ?",
            [$name]
        )) > 0;
    }
};
