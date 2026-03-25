<?php

namespace App\Mail;

use App\Models\PurchaseOrder;
use App\Services\PurchaseOrderExcelExport;
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
        $pdf         = Pdf::loadView('pdf.purchase-order', ['order' => $this->order]);
        $pdfFilename = str_replace('/', '-', $this->order->number) . '.pdf';

        $excelPath     = PurchaseOrderExcelExport::generate($this->order);
        $excelFilename = str_replace('/', '-', $this->order->number) . '.xlsx';

        return [
            Attachment::fromData(fn () => $pdf->output(), $pdfFilename)
                ->withMime('application/pdf'),
            Attachment::fromPath($excelPath)
                ->as($excelFilename)
                ->withMime('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
        ];
    }
}
