<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiUsageLog extends Model
{
    protected $fillable = [
        'source',
        'model',
        'input_tokens',
        'output_tokens',
        'cost_usd',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata'      => 'array',
            'input_tokens'  => 'integer',
            'output_tokens' => 'integer',
            'cost_usd'      => 'float',
        ];
    }

    // Prețuri per million tokens (USD)
    const PRICES = [
        'claude-haiku-4-5-20251001' => ['input' => 0.80,  'output' => 4.00],
        'claude-haiku-4-5'          => ['input' => 0.80,  'output' => 4.00],
        'claude-sonnet-4-6'         => ['input' => 3.00,  'output' => 15.00],
        'claude-opus-4-6'           => ['input' => 15.00, 'output' => 75.00],
    ];

    // Label-uri prietenoase pentru surse
    const SOURCE_LABELS = [
        'chatbot'              => 'Chatbot site',
        'email_ai'             => 'Email AI',
        'chat_summary'         => 'Rezumat chat',
        'domain_match'         => 'Domain matching furnizori',
        'bi_daily'             => 'BI — Raport zilnic',
        'bi_weekly'            => 'BI — Raport săptămânal',
        'bi_monthly'           => 'BI — Raport lunar',
        'bi_quarterly'         => 'BI — Raport trimestrial',
        'bi_semiannual'        => 'BI — Raport semestrial',
        'bi_annual'            => 'BI — Raport anual',
        'product_descriptions' => 'Enrichment — Descrieri produse',
        'product_titles'       => 'Enrichment — Titluri produse',
        'product_attributes'   => 'Enrichment — Atribute produse',
        'product_categories'   => 'Enrichment — Categorii produse',
        'product_images_eval'  => 'Enrichment — Evaluare imagini',
        'product_images_verify'  => 'Enrichment — Verificare imagini',
        'product_dimensions'     => 'Enrichment — Dimensiuni produse',
        'product_enrich_web'     => 'Enrichment — Web scraping',
        'social_caption'         => 'Social Media — Generare caption',
        'social_style_analysis'  => 'Social Media — Analiză stil',
        'social_image_gen'       => 'Social Media — Generare imagine (Gemini)',
        'template_generation'    => 'Social Media — Generare template-uri grafice',
    ];

    /**
     * Înregistrează un apel API Claude.
     */
    public static function record(
        string $source,
        string $model,
        int $inputTokens,
        int $outputTokens,
        array $metadata = []
    ): self {
        $prices = self::PRICES[$model] ?? ['input' => 0.80, 'output' => 4.00];
        $cost   = round(
            ($inputTokens  / 1_000_000 * $prices['input']) +
            ($outputTokens / 1_000_000 * $prices['output']),
            6
        );

        return self::create([
            'source'        => $source,
            'model'         => $model,
            'input_tokens'  => $inputTokens,
            'output_tokens' => $outputTokens,
            'cost_usd'      => $cost,
            'metadata'      => $metadata ?: null,
        ]);
    }

    /**
     * Costul total în USD pentru o perioadă.
     */
    public static function totalCostForPeriod(string $from, string $to): float
    {
        return (float) self::whereBetween('created_at', [$from, $to])->sum('cost_usd');
    }

    public function getSourceLabelAttribute(): string
    {
        return self::SOURCE_LABELS[$this->source] ?? $this->source;
    }
}
