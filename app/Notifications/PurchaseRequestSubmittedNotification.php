<?php

namespace App\Notifications;

use App\Models\PurchaseRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PurchaseRequestSubmittedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly PurchaseRequest $request,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->request->loadMissing(['user', 'location', 'items']);
        $itemCount = $this->request->items->count();
        $urgent    = $this->request->items->where('is_urgent', true)->count();

        $mail = (new MailMessage)
            ->subject("Necesar nou de achiziție: {$this->request->number}")
            ->greeting("Salut, {$notifiable->name}!")
            ->line("A fost înregistrat un necesar nou de la **{$this->request->user?->name}** ({$this->request->location?->name}):")
            ->line("**Număr:** {$this->request->number}")
            ->line("**Produse:** {$itemCount}" . ($urgent > 0 ? " ({$urgent} urgente)" : ''));

        if ($this->request->notes) {
            $mail->line("**Observații:** {$this->request->notes}");
        }

        return $mail
            ->action('Vezi necesarul', url("/app/purchase-requests/{$this->request->id}"))
            ->line('Accesează dashboard-ul de achiziții pentru a procesa necesarul.');
    }

    public function toDatabase(object $notifiable): array
    {
        $this->request->loadMissing(['user', 'location', 'items']);
        $urgent = $this->request->items->where('is_urgent', true)->count();

        return [
            'title'  => "Necesar nou: {$this->request->number}",
            'body'   => "De la {$this->request->user?->name}" . ($urgent > 0 ? " — {$urgent} urgente" : '') . " ({$this->request->location?->name})",
            'icon'   => 'heroicon-o-clipboard-document-list',
            'color'  => $urgent > 0 ? 'warning' : 'info',
            'url'    => "/app/purchase-requests/{$this->request->id}",
        ];
    }
}
