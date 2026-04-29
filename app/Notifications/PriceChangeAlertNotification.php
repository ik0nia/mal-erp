<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PriceChangeAlertNotification extends Notification
{
    use Queueable;

    /**
     * @param array $spikes   [{name, sku, pct, old_price, new_price, supplier, sell_price, margin}]
     * @param array $drops    [same structure]
     * @param array $margins  [{name, sku, purchase_price, sell_price, margin, supplier}]  — marjă sub prag
     */
    public function __construct(
        public readonly array $spikes,
        public readonly array $drops,
        public readonly array $margins,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): \Illuminate\Mail\Mailable
    {
        $spikes        = $this->spikes;
        $drops         = $this->drops;
        $margins       = $this->margins;
        $recipientName = $notifiable->name;
        $total         = count($spikes) + count($drops) + count($margins);

        $parts = [];
        if (count($spikes))  $parts[] = count($spikes)  . ' creșteri';
        if (count($drops))   $parts[] = count($drops)   . ' scăderi';
        if (count($margins)) $parts[] = count($margins) . ' marje mici';
        $subject = '[ERP Malinco] Alerte prețuri — ' . implode(', ', $parts);

        return new class($spikes, $drops, $margins, $recipientName, $subject) extends \Illuminate\Mail\Mailable {
            public function __construct(
                public array $spikes,
                public array $drops,
                public array $margins,
                public string $recipientName,
                public string $mailSubject,
            ) {}

            public function build(): static
            {
                return $this->subject($this->mailSubject)
                    ->view('mail.price-alert', [
                        'spikes'        => $this->spikes,
                        'drops'         => $this->drops,
                        'margins'       => $this->margins,
                        'recipientName' => $this->recipientName,
                        'subject'       => $this->mailSubject,
                    ]);
            }
        };
    }

    public function toDatabase(object $notifiable): array
    {
        $parts = [];
        if (count($this->spikes))  $parts[] = count($this->spikes)  . ' creșteri';
        if (count($this->drops))   $parts[] = count($this->drops)   . ' scăderi';
        if (count($this->margins)) $parts[] = count($this->margins) . ' marje mici';

        return [
            'title' => 'Alerte prețuri achiziție: ' . implode(', ', $parts),
            'body'  => 'Prețurile de vânzare nu au fost actualizate după modificările de achiziție.',
            'icon'  => 'heroicon-o-exclamation-triangle',
            'color' => 'warning',
            'url'   => '/app/produse',
        ];
    }
}
