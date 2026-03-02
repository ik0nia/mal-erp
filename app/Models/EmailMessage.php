<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailMessage extends Model
{
    protected $fillable = [
        'imap_uid',
        'imap_folder',
        'from_email',
        'from_name',
        'subject',
        'body_html',
        'body_text',
        'to_recipients',
        'cc_recipients',
        'attachments',
        'sent_at',
        'is_read',
        'is_flagged',
        'supplier_id',
        'purchase_order_id',
        'agent_processed_at',
        'agent_actions',
        'internal_notes',
    ];

    protected $casts = [
        'sent_at'            => 'datetime',
        'agent_processed_at' => 'datetime',
        'is_read'            => 'boolean',
        'is_flagged'         => 'boolean',
        'to_recipients'      => 'array',
        'cc_recipients'      => 'array',
        'attachments'        => 'array',
        'agent_actions'      => 'array',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function supplierContact(): BelongsTo
    {
        return $this->belongsTo(SupplierContact::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function getFromLabelAttribute(): string
    {
        return $this->from_name
            ? "{$this->from_name} <{$this->from_email}>"
            : $this->from_email;
    }

    public function hasAttachments(): bool
    {
        return ! empty($this->attachments);
    }

    /**
     * Returnează conținutul HTML curat pentru afișare (fără wrapper html/head/body).
     */
    public function getBodyDisplayAttribute(): string
    {
        $html = $this->body_html ?? '';

        if ($html === '') {
            return $this->body_text
                ? '<pre style="font-family:sans-serif;white-space:pre-wrap">' . htmlspecialchars($this->body_text, ENT_QUOTES, 'UTF-8') . '</pre>'
                : '';
        }

        // Extrage conținutul din <body> dacă există
        if (preg_match('/<body[^>]*>(.*?)<\/body>/si', $html, $matches)) {
            return trim($matches[1]);
        }

        return $html;
    }
}
