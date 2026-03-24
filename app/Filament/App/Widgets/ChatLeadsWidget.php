<?php

namespace App\Filament\App\Widgets;

use App\Models\ChatContact;
use App\Models\ChatLog;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

class ChatLeadsWidget extends Widget
{
    protected static ?int      $sort       = -8;
    protected static bool      $isLazy     = false;
    protected int|string|array $columnSpan = 'full';
    protected string    $view            = 'filament.widgets.chat-leads-widget';

    public ?string $openSession = null;

    public static function canView(): bool
    {
        return \App\Models\RolePermission::check(static::class, 'can_access');
    }

    public function getLeads(): Collection
    {
        return ChatContact::whereNull('contacted_at')
            ->where(fn ($q) => $q->whereNotNull('email')->orWhereNotNull('phone'))
            ->orderByDesc('created_at')
            ->get();
    }

    public function getLeadsData(): array
    {
        return $this->getLeads()->map(fn (ChatContact $c) => [
            'session_id'       => $c->session_id,
            'email'            => $c->email,
            'phone'            => $c->phone,
            'wants_specialist' => (bool) $c->wants_specialist,
            'summary'          => $c->summary,
            'ago'              => \Carbon\Carbon::parse($c->created_at)->diffForHumans(),
        ])->toArray();
    }

    public function getModalData(): ?array
    {
        if (! $this->openSession) {
            return null;
        }

        $contact = ChatContact::where('session_id', $this->openSession)->first();
        if (! $contact) {
            return null;
        }

        $messages = ChatLog::where('session_id', $this->openSession)
            ->orderBy('created_at')
            ->get(['role', 'content', 'created_at'])
            ->map(fn ($m) => [
                'role'    => $m->role,
                'content' => $m->content,
                'time'    => \Carbon\Carbon::parse($m->created_at)->format('H:i'),
            ])->toArray();

        return [
            'session_id'       => $contact->session_id,
            'email'            => $contact->email,
            'phone'            => $contact->phone,
            'wants_specialist' => (bool) $contact->wants_specialist,
            'summary'          => $contact->summary,
            'ago'              => \Carbon\Carbon::parse($contact->created_at)->diffForHumans(),
            'messages'         => $messages,
        ];
    }

    public function openModal(string $sessionId): void
    {
        $this->openSession = $sessionId;
    }

    public function closeModal(): void
    {
        $this->openSession = null;
    }

    public function markAsContacted(string $sessionId): void
    {
        ChatContact::where('session_id', $sessionId)->update([
            'contacted_at' => now(),
            'contacted_by' => auth()->id(),
        ]);

        $this->openSession = null;

        Notification::make()
            ->title('Lead marcat ca și contactat')
            ->success()
            ->send();
    }
}
