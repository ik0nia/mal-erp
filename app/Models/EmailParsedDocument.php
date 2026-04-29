<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailParsedDocument extends Model
{
    protected $fillable = [
        'email_message_id',
        'supplier_id',
        'attachment_name',
        'attachment_index',
        'source_type',
        'doc_type',
        'doc_number',
        'doc_date',
        'parsed_data',
        'products',
        'winmentor_doc_nr',
        'match_status',
        'discrepancies',
        'processing_status',
        'error_message',
    ];

    protected $casts = [
        'parsed_data'   => 'array',
        'products'      => 'array',
        'discrepancies' => 'array',
        'doc_date'      => 'date',
    ];

    public function emailMessage(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
