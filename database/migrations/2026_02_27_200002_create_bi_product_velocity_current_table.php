<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1 rând per produs — NU istoric pe zile.
        // Motivul pentru reference_product_id (nu woo_product_id) ca PK:
        //   - daily_stock_metrics îl folosește ca cheie primară
        //   - woo_product_id se poate schimba dacă produsul e recreat
        //   - toate query-urile BI joinează pe reference_product_id (SKU/EAN WinMentor)
        Schema::create('bi_product_velocity_current', function (Blueprint $table) {
            $table->string('reference_product_id', 100)->primary();
            $table->date('calculated_for_day');             // ziua pentru care e valabilă calculul

            // Ieșiri cumulate (max(0, -daily_available_variation)) pe ferestre rolling
            $table->decimal('out_qty_7d',  12, 3)->default(0);
            $table->decimal('out_qty_30d', 12, 3)->default(0);
            $table->decimal('out_qty_90d', 12, 3)->default(0);

            // Medie zilnică = out_qty_Xd / X (chiar dacă există mai puțin istoric)
            $table->decimal('avg_out_qty_7d',  12, 4)->default(0);
            $table->decimal('avg_out_qty_30d', 12, 4)->default(0);
            $table->decimal('avg_out_qty_90d', 12, 4)->default(0);

            // Ultima zi cu mișcare (variation != 0)
            $table->date('last_movement_day')->nullable();
            $table->unsignedInteger('days_since_last_movement')->nullable();

            $table->timestamp('updated_at')->nullable();

            $table->index('calculated_for_day'); // filtrare rapidă după zi
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bi_product_velocity_current');
    }
};
