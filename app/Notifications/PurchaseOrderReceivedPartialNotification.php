<?php

namespace App\Notifications;

use App\Models\PurchaseOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PurchaseOrderReceivedPartialNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly PurchaseOrder $order,
        /** @var array<string> Lista de produse cu lipsuri */
        public readonly array         $shortfallProducts,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->order->loadMissing(['supplier']);
        $productList = implode(', ', array_slice($this->shortfallProducts, 0, 5));
        if (count($this->shortfallProducts) > 5) {
            $productList .= ' și altele';
        }

        return (new MailMessage)
            ->subject("Lipsuri la recepție — {$this->order->number}")
            ->greeting("Salut, {$notifiable->name}!")
            ->line("Comanda **{$this->order->number}** de la **{$this->order->supplier?->name}** a fost recepționată parțial.")
            ->line("**Produse cu lipsuri:** {$productList}")
            ->line('Produsele nelivrate au fost returnate automat în coada de achiziții.')
            ->action('Vezi comanda', url("/app/purchase-orders/{$this->order->id}"))
            ->line('Verifică necesarele tale pentru statusul actualizat.');
    }

    public function toDatabase(object $notifiable): array
    {
        $this->order->loadMissing(['supplier']);
        $count = count($this->shortfallProducts);

        return [
            'title'  => "Lipsuri recepție: {$this->order->number}",
            'body'   => "{$this->order->supplier?->name} — {$count} " . ($count === 1 ? 'produs' : 'produse') . ' cu lipsuri',
            'icon'   => 'heroicon-o-exclamation-triangle',
            'color'  => 'warning',
            'url'    => "/app/purchase-orders/{$this->order->id}",
        ];
    }
}
