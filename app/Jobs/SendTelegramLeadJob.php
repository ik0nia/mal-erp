<?php

namespace App\Jobs;

use App\Models\ChatContact;
use App\Services\TelegramService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendTelegramLeadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 15;

    public function __construct(private readonly string $sessionId) {}

    public function handle(TelegramService $telegram): void
    {
        if (! $telegram->isConfigured()) {
            return;
        }

        $contact = ChatContact::where('session_id', $this->sessionId)->first();
        if (! $contact) {
            return;
        }

        $telegram->sendLeadNotification(
            email:           $contact->email,
            phone:           $contact->phone,
            wantsSpecialist: (bool) $contact->wants_specialist,
            interestedIn:    $contact->interested_in,
            summary:         $contact->summary,
            sessionId:       $this->sessionId,
        );
    }
}
