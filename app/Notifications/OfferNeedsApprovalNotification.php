<?php

namespace App\Notifications;

use App\Filament\App\Resources\OfferResource;
use App\Models\Offer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OfferNeedsApprovalNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Offer $offer,
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
        $this->offer->loadMissing(['user', 'items']);
        $total = number_format((float) $this->offer->total, 2, ',', '.') . ' ' . $this->offer->currency;
        $maxDiscount = (float) $this->offer->items->max('discount_percent');

        return (new MailMessage)
            ->subject("Ofertă {$this->offer->number} — discount peste plafon")
            ->greeting("Salut, {$notifiable->name}!")
            ->line("Oferta **{$this->offer->number}** are un discount care depășește plafonul operatorului și necesită aprobarea ta.")
            ->line("**Client:** " . ($this->offer->client_company ?: $this->offer->client_name))
            ->line("**Discount maxim pe ofertă:** " . number_format($maxDiscount, 2, ',', '.') . '%')
            ->line("**Valoare totală:** {$total}")
            ->line("**Întocmită de:** {$this->offer->user?->name}")
            ->action('Aprobă / Respinge', $this->url())
            ->line('Trimiterea ofertei către client este blocată până la aprobare.');
    }

    public function toDatabase(object $notifiable): array
    {
        $total = number_format((float) $this->offer->total, 2, ',', '.') . ' ' . $this->offer->currency;

        return [
            'title' => "Aprobare discount: {$this->offer->number}",
            'body'  => ($this->offer->client_company ?: $this->offer->client_name) . " — {$total}",
            'icon'  => 'heroicon-o-receipt-percent',
            'color' => 'warning',
            'url'   => $this->url(),
        ];
    }
}
