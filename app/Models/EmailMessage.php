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
     * Sanitizează HTML din email pentru a preveni XSS — symfony/html-sanitizer
     * (allowlist reală; vechiul regex-blacklist era bypassabil și ținea doar
     * CSP-ul + sandbox-ul iframe-ului). Emailurile au nevoie de tabele, stiluri
     * inline și imagini (inclusiv data:image pentru cele embedate).
     */
    public static function sanitizeEmailHtml(string $html): string
    {
        if ($html === '') {
            return '';
        }

        static $sanitizer = null;

        if ($sanitizer === null) {
            $config = (new \Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig)
                ->allowSafeElements()
                // layout de email: tabele + imagini cu atributele lor istorice
                ->allowElement('table', ['border', 'cellpadding', 'cellspacing', 'width', 'align', 'bgcolor', 'style'])
                ->allowElement('img', ['src', 'alt', 'title', 'width', 'height', 'border', 'style'])
                ->allowElement('center')
                ->allowAttribute('style', '*')
                ->allowAttribute('width', '*')
                ->allowAttribute('height', '*')
                ->allowAttribute('align', '*')
                ->allowAttribute('valign', '*')
                ->allowAttribute('bgcolor', '*')
                ->allowLinkSchemes(['https', 'http', 'mailto', 'tel'])
                ->allowMediaSchemes(['https', 'http', 'data', 'cid'])
                // emailurile comerciale sunt lungi — default-ul de 20k ar trunchia
                ->withMaxInputLength(2_000_000);

            $sanitizer = new \Symfony\Component\HtmlSanitizer\HtmlSanitizer($config);
        }

        return $sanitizer->sanitize($html);
    }
}
