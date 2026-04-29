<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WinmentorApiLog extends Model
{
    protected $fillable = [
        'method',
        'endpoint',
        'request_body',
        'response_body',
        'status_code',
        'duration_ms',
        'success',
        'context',
        'purchase_order_id',
    ];

    protected function casts(): array
    {
        return [
            'success'    => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /**
     * Înregistrează un apel API WinMentor Bridge în baza de date.
     */
    public static function record(
        string $method,
        string $endpoint,
        mixed  $requestBody,
        mixed  $responseBody,
        int    $statusCode,
        int    $durationMs,
        bool   $success,
        ?string $context = null,
        ?int   $purchaseOrderId = null,
    ): void {
        try {
            static::create([
                'method'            => strtoupper($method),
                'endpoint'          => $endpoint,
                'request_body'      => is_string($requestBody)
                    ? substr($requestBody, 0, 10000)
                    : substr(json_encode($requestBody, JSON_UNESCAPED_UNICODE), 0, 10000),
                'response_body'     => is_string($responseBody)
                    ? substr($responseBody, 0, 10000)
                    : substr(json_encode($responseBody, JSON_UNESCAPED_UNICODE), 0, 10000),
                'status_code'       => $statusCode,
                'duration_ms'       => min($durationMs, 65535),
                'success'           => $success,
                'context'           => $context,
                'purchase_order_id' => $purchaseOrderId,
            ]);
        } catch (\Throwable) {
            // Nu blocăm fluxul dacă logging-ul eșuează
        }
    }
}
