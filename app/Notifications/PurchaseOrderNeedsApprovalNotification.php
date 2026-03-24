<?php

namespace App\Notifications;

use App\Models\PurchaseOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PurchaseOrderNeedsApprovalNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly PurchaseOrder $order,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->order->loadMissing(['supplier', 'buyer', 'items']);
        $total = number_format((float) $this->order->total_value, 2, ',', '.') . ' ' . $this->order->currency;

        return (new MailMessage)
            ->subject("Comanda {$this->order->number} necesită aprobare")
            ->greeting("Salut, {$notifiable->name}!")
            ->line("Comanda de achiziție **{$this->order->number}** necesită aprobarea ta.")
            ->line("**Furnizor:** {$this->order->supplier?->name}")
            ->line("**Valoare totală:** {$total}")
            ->line("**Creat de:** {$this->order->buyer?->name}")
            ->action('Aprobă / Respinge', url("/app/purchase-orders/{$this->order->id}"))
            ->line('Accesează comanda pentru a aproba sau respinge.');
    }

    public function toDatabase(object $notifiable): array
    {
        $this->order->loadMissing(['supplier', 'buyer']);
        $total = number_format((float) $this->order->total_value, 2, ',', '.') . ' ' . $this->order->currency;

        return [
            'title'  => "Aprobare necesară: {$this->order->number}",
            'body'   => "{$this->order->supplier?->name} — {$total}",
            'icon'   => 'heroicon-o-clock',
            'color'  => 'warning',
            'url'    => "/app/purchase-orders/{$this->order->id}",
        ];
    }
}
