<?php

namespace App\Mail;

use App\Models\PurchaseOrder;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class PurchaseOrderMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string        $emailSubject,
        public readonly string        $emailBody,
        public readonly PurchaseOrder $order,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->emailSubject);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.purchase-order');
    }

    public function attachments(): array
    {
        $pdf      = Pdf::loadView('pdf.purchase-order', ['order' => $this->order]);
        $filename = str_replace('/', '-', $this->order->number) . '.pdf';

        return [
            Attachment::fromData($pdf->output(), $filename)
                ->withMime('application/pdf'),
        ];
    }
}
