<?php

namespace App\Notifications;

use App\Filament\App\Resources\OfferResource;
use App\Models\Offer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OfferApprovalDecisionNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Offer $offer,
        public readonly bool  $approved,
        public readonly ?string $note = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    private function url(): string
    {
        return OfferResource::getUrl('view', ['record' => $this->offer->getKey()], panel: 'app');
    }

    public function toMail(object $notifiable): MailMessage
    {
        $verb = $this->approved ? 'aprobat' : 'respins';

        $mail = (new MailMessage)
            ->subject("Discount {$verb}: oferta {$this->offer->number}")
            ->greeting("Salut, {$notifiable->name}!")
            ->line("Discountul de pe oferta **{$this->offer->number}** a fost **{$verb}**.");

        if (filled($this->note)) {
            $mail->line("**Notă:** {$this->note}");
        }

        if ($this->approved) {
            $mail->line('Poți trimite oferta către client.');
        } else {
            $mail->line('Ajustează discountul conform plafonului și reia procesul.');
        }

        return $mail->action('Deschide oferta', $this->url());
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Discount ' . ($this->approved ? 'aprobat' : 'respins') . ": {$this->offer->number}",
            'body'  => filled($this->note) ? $this->note : ($this->approved ? 'Poți trimite oferta.' : 'Necesită ajustare.'),
            'icon'  => $this->approved ? 'heroicon-o-check-badge' : 'heroicon-o-x-circle',
            'color' => $this->approved ? 'success' : 'danger',
            'url'   => $this->url(),
        ];
    }
}
