<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
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

    protected function fromLabel(): Attribute
    {
        return Attribute::make(
            get: fn (): string => $this->from_name
                ? "{$this->from_name} <{$this->from_email}>"
                : $this->from_email,
        );
    }

    public function hasAttachments(): bool
    {
        return ! empty($this->attachments);
    }

    /**
     * Returnează conținutul HTML curat pentru afișare (fără wrapper html/head/body).
     */
    protected function bodyDisplay(): Attribute
    {
        return Attribute::make(
            get: function (): string {
                $html = $this->body_html ?? '';

                if ($html === '') {
                    return $this->body_text
                        ? '<pre style="font-family:sans-serif;white-space:pre-wrap">' . htmlspecialchars($this->body_text, ENT_QUOTES, 'UTF-8') . '</pre>'
                        : '';
                }

                // Extrage conținutul din <body> dacă există
                if (preg_match('/<body[^>]*>(.*?)<\/body>/si', $html, $matches)) {
                    return static::sanitizeEmailHtml(trim($matches[1]));
                }

                return static::sanitizeEmailHtml($html);
            },
        );
    }

    /**
     * Sanitizează HTML din email pentru a preveni XSS.
     * Elimină taguri periculoase, atribute event handler și protocoale javascript:.
     */
    public static function sanitizeEmailHtml(string $html): string
    {
        if ($html === '') {
            return '';
        }

        // Elimină complet taguri periculoase împreună cu conținutul lor
        $html = preg_replace('/<(script|iframe|object|embed|form)\b[^>]*>.*?<\/\1>/is', '', $html);

        // Elimină taguri self-closing periculoase
        $html = preg_replace('/<(script|iframe|object|embed|form)\b[^>]*\/?>/i', '', $html);

        // Elimină atribute event handler (onclick, onerror, onload, etc.)
        $html = preg_replace('/\s+on\w+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);

        // Înlocuiește javascript: cu # în atributele href/src/action
        $html = preg_replace('/\b(href|src|action)\s*=\s*["\']?\s*javascript\s*:/i', '$1="#" data-blocked="', $html);
        $html = preg_replace('/javascript\s*:/i', '#', $html);

        // Elimină atributul srcdoc (poate conține HTML arbitrar)
        $html = preg_replace('/\s+srcdoc\s*=\s*(?:"[^"]*"|\'[^\']*\')/i', '', $html);

        // Elimină data: URLs în href/src (pot conține HTML/JS)
        $html = preg_replace('/\b(href|src)\s*=\s*["\']data:(?!image\/)[^"\']*["\']/i', '$1="#"', $html);

        return $html;
    }
}
