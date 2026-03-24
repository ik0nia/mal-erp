<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('graphic_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->json('config');
            $table->string('preview_image')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Seed template implicit
        DB::table('graphic_templates')->insert([
            'name'       => 'Template Malinco (default)',
            'slug'       => 'malinco-default',
            'is_active'  => true,
            'config'     => json_encode([
                'primary_color'      => '#a52a3f',
                'bottom_text'        => 'ASIGURĂM TRANSPORT ȘI DESCĂRCARE CU MACARA',
                'bottom_subtext'     => 'Sântandrei, Nr. 311, vis-a-vis de Primărie  |  www.malinco.ro  |  0359 444 999',
                'cta_text'           => 'malinco.ro  →',
                'show_rainbow_bar'   => true,
                'show_truck'         => true,
                'logo_scale'         => 0.36,
                'title_size_pct'     => 0.075,
                'subtitle_size_pct'  => 0.029,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('graphic_templates');
    }
};
