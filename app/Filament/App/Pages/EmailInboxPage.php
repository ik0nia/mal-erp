<?php

namespace App\Filament\App\Pages;

use App\Jobs\FetchEmailsJob;
use App\Models\EmailMessage;
use App\Models\Supplier;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

class EmailInboxPage extends Page
{
    protected static ?string $navigationIcon  = 'heroicon-o-envelope';
    protected static ?string $navigationLabel = 'Inbox Email';
    protected static ?string $navigationGroup = 'Comunicare';
    protected static ?int    $navigationSort  = 1;
    protected static string  $view            = 'filament.app.pages.email-inbox';

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public ?int    $selectedId   = null;
    public string  $filterRead   = 'all';  // all | unread | read
    public string  $filterFolder = '';     // '' = toate folderele
    public string  $search       = '';

    public function mount(): void
    {
        $this->selectedId = EmailMessage::orderByDesc('sent_at')->value('id');
    }

    public function getEmails(): Collection
    {
        return EmailMessage::query()
            ->when($this->filterRead === 'unread', fn ($q) => $q->where('is_read', false))
            ->when($this->filterRead === 'read',   fn ($q) => $q->where('is_read', true))
            ->when($this->filterFolder !== '',     fn ($q) => $q->where('imap_folder', $this->filterFolder))
            ->when($this->search, fn ($q) => $q->where(function ($q) {
                $q->where('subject',    'like', "%{$this->search}%")
                  ->orWhere('from_email', 'like', "%{$this->search}%")
                  ->orWhere('from_name',  'like', "%{$this->search}%");
            }))
            ->with('supplier')
            ->orderByDesc('sent_at')
            ->limit(300)
            ->get();
    }

    public function getSelectedEmail(): ?EmailMessage
    {
        if (! $this->selectedId) {
            return null;
        }
        return EmailMessage::with(['supplier', 'purchaseOrder'])->find($this->selectedId);
    }

    /** Returnează folderele distincte existente în DB, cu numărul de emailuri. */
    public function getFolders(): Collection
    {
        return EmailMessage::selectRaw('imap_folder, COUNT(*) as total')
            ->groupBy('imap_folder')
            ->orderBy('imap_folder')
            ->get()
            ->map(fn ($row) => [
                'path'  => $row->imap_folder,
                'label' => $this->folderLabel($row->imap_folder),
                'total' => $row->total,
            ]);
    }

    /** Nume prietenos pentru un folder IMAP. */
    private function folderLabel(string $path): string
    {
        $map = [
            'INBOX'        => 'Inbox',
            'INBOX.Sent'   => 'Trimise',
            'INBOX.Drafts' => 'Ciorne',
            'INBOX.Trash'  => 'Coș',
            'INBOX.spam'   => 'Spam',
            'INBOX.Junk'   => 'Junk',
            'INBOX.Archive'   => 'Arhivă',
            'INBOX.Archives'  => 'Arhivă',
        ];

        if (isset($map[$path])) {
            return $map[$path];
        }

        // INBOX.Archives.2025 → Arhivă 2025
        if (str_starts_with($path, 'INBOX.Archives.') || str_starts_with($path, 'INBOX.Archive.')) {
            $sub = substr($path, strrpos($path, '.') + 1);
            return 'Arhivă ' . $sub;
        }

        // Fallback: ultimul segment
        return substr($path, strrpos($path, '.') + 1);
    }

    public function setFolder(string $folder): void
    {
        $this->filterFolder = $folder;
        $this->selectedId   = null;
    }

    public function setFilterRead(string $value): void
    {
        $this->filterRead = $value;
        $this->selectedId = null;
    }

    public function selectEmail(int $id): void
    {
        $this->selectedId = $id;
        // Nu marcăm ca citit — utilizatorul decide explicit
    }

    public function toggleFlag(int $id): void
    {
        $msg = EmailMessage::find($id);
        if ($msg) {
            $msg->update(['is_flagged' => ! $msg->is_flagged]);
        }
    }

    public function getUnreadCount(): int
    {
        return EmailMessage::where('is_read', false)
            ->when($this->filterFolder !== '', fn ($q) => $q->where('imap_folder', $this->filterFolder))
            ->count();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sync')
                ->label('Sincronizează')
                ->icon('heroicon-o-arrow-path')
                ->action(function () {
                    FetchEmailsJob::dispatch();
                    Notification::make()
                        ->title('Sincronizare pornită')
                        ->body('Emailurile noi vor apărea în câteva secunde.')
                        ->success()
                        ->send();
                }),

            Action::make('saveNotes')
                ->label('Salvează notă')
                ->icon('heroicon-o-pencil-square')
                ->form([
                    Textarea::make('internal_notes')
                        ->label('Notă internă')
                        ->rows(3)
                        ->default(fn () => $this->getSelectedEmail()?->internal_notes),
                ])
                ->action(function (array $data) {
                    if ($this->selectedId) {
                        EmailMessage::where('id', $this->selectedId)
                            ->update(['internal_notes' => $data['internal_notes']]);
                        Notification::make()->title('Notă salvată')->success()->send();
                    }
                })
                ->visible(fn () => $this->selectedId !== null),

            Action::make('associateSupplier')
                ->label('Asociază furnizor')
                ->icon('heroicon-o-link')
                ->form([
                    Select::make('supplier_id')
                        ->label('Furnizor')
                        ->options(Supplier::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->native(false)
                        ->default(fn () => $this->getSelectedEmail()?->supplier_id),
                ])
                ->action(function (array $data) {
                    if ($this->selectedId) {
                        EmailMessage::where('id', $this->selectedId)
                            ->update(['supplier_id' => $data['supplier_id'] ?: null]);
                        Notification::make()->title('Furnizor asociat')->success()->send();
                    }
                })
                ->visible(fn () => $this->selectedId !== null),
        ];
    }
}
