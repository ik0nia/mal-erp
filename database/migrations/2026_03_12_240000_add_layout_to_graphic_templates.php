<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('graphic_templates', function (Blueprint $table) {
            $table->string('layout')->default('product')->after('slug');
            // layout: product | brand
        });

        // Update template existent
        DB::table('graphic_templates')
            ->where('slug', 'malinco-default')
            ->update(['layout' => 'product']);

        // Seed template brand
        DB::table('graphic_templates')->insert([
            'name'      => 'Template Brand Partener',
            'slug'      => 'malinco-brand',
            'layout'    => 'brand',
            'is_active' => true,
            'config'    => json_encode([
                'layout'             => 'brand',
                'primary_color'      => '#a52a3f',
                'bottom_text'        => 'ASIGURĂM TRANSPORT ȘI DESCĂRCARE CU MACARA',
                'bottom_subtext'     => 'Sântandrei, Nr. 311, vis-a-vis de Primărie  |  www.malinco.ro  |  0359 444 999',
                'cta_text'           => 'malinco.ro  →',
                'show_rainbow_bar'   => true,
                'show_truck'         => true,
                'logo_scale'         => 0.36,
                'title_size_pct'     => 0.082,
                'subtitle_size_pct'  => 0.032,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('graphic_templates', function (Blueprint $table) {
            $table->dropColumn('layout');
        });
    }
};
