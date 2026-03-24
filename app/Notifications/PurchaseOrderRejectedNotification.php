<?php

namespace App\Notifications;

use App\Models\PurchaseOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PurchaseOrderRejectedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly PurchaseOrder $order,
        public readonly string        $rejectedByName,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->order->loadMissing(['supplier']);

        return (new MailMessage)
            ->subject("Comanda {$this->order->number} a fost respinsă")
            ->greeting("Salut, {$notifiable->name}!")
            ->line("Comanda **{$this->order->number}** a fost **respinsă** de {$this->rejectedByName}.")
            ->line("**Furnizor:** {$this->order->supplier?->name}")
            ->line("**Motiv:** {$this->order->rejection_reason}")
            ->action('Vezi comanda', url("/app/purchase-orders/{$this->order->id}"))
            ->line('Accesează comanda pentru detalii sau pentru a corecta și retrimite.');
    }

    public function toDatabase(object $notifiable): array
    {
        $this->order->loadMissing(['supplier']);

        return [
            'title'  => "Comandă respinsă: {$this->order->number}",
            'body'   => "{$this->order->supplier?->name} — {$this->order->rejection_reason}",
            'icon'   => 'heroicon-o-x-circle',
            'color'  => 'danger',
            'url'    => "/app/purchase-orders/{$this->order->id}",
        ];
    }
}
