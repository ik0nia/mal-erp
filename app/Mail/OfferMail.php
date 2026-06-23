<?php

namespace App\Mail;

use App\Models\Offer;
use App\Services\Offers\OfferPdf;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Log;

class OfferMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $emailSubject,
        public readonly string $emailBody,
        public readonly Offer  $offer,
    ) {}

    public function envelope(): Envelope
    {
        $fromAddress = config('mail.from.address');
        $fromName = $this->offer->location?->company_name ?: 'Malinco';

        return new Envelope(
            from: $fromAddress ? new Address($fromAddress, $fromName) : null,
            subject: $this->emailSubject,
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.offer');
    }

    public function attachments(): array
    {
        $attachments = [];

        try {
            $pdf = OfferPdf::generateAndStore($this->offer);
            $attachments[] = Attachment::fromData(fn () => $pdf, OfferPdf::filename($this->offer))
                ->withMime('application/pdf');
        } catch (\Throwable $e) {
            Log::error('Offer Mail: PDF generation failed', [
                'offer' => $this->offer->number,
                'error' => $e->getMessage(),
            ]);
        }

        return $attachments;
    }
}
