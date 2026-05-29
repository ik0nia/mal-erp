<?php

namespace App\Mail;

use App\Models\EanAssociationRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EanAssociationRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly EanAssociationRequest $request,
    ) {}

    public function envelope(): Envelope
    {
        $ean = $this->request->ean;
        $productName = $this->request->product?->name ?? 'nespecificat';

        return new Envelope(
            subject: "Cerere asociere EAN: {$ean} → {$productName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.ean-association-request',
        );
    }
}
