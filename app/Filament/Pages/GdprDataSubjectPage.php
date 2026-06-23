<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\Gdpr\DataSubjectService;
use App\Services\Gdpr\ErasureService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class GdprDataSubjectPage extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-identification';

    protected static string|\UnitEnum|null $navigationGroup = 'Conformitate';

    protected static ?string $navigationLabel = 'Date persoană vizată (GDPR)';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.pages.gdpr-data-subject';

    public string $identifier = '';

    public string $confirmText = '';

    public ?array $located = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isSuperAdmin();
    }

    public function search(): void
    {
        $this->confirmText = '';
        $id = trim($this->identifier);
        if ($id === '') {
            $this->located = null;
            Notification::make()->title('Introdu un email sau telefon.')->warning()->send();

            return;
        }
        $this->located = app(DataSubjectService::class)->locate($id);
    }

    public function totalFound(): int
    {
        return $this->located ? app(DataSubjectService::class)->countRecords($this->located) : 0;
    }

    public function downloadExport()
    {
        if (! $this->located || $this->totalFound() === 0) {
            Notification::make()->title('Nimic de exportat.')->warning()->send();

            return null;
        }

        $name = 'gdpr-export/'.Str::slug($this->located['identifier']).'-'.now()->format('Ymd-His').'.json';
        Storage::disk('local')->put($name, json_encode($this->located, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return Storage::disk('local')->download($name);
    }

    public function erase(): void
    {
        if (! $this->located || $this->totalFound() === 0) {
            return;
        }
        if (trim($this->confirmText) !== trim($this->identifier)) {
            Notification::make()->title('Confirmare incorectă')
                ->body('Tastează exact identificatorul pentru a confirma ștergerea.')->danger()->send();

            return;
        }

        $summary = app(ErasureService::class)->erase($this->located, dryRun: false);
        $total = array_sum($summary);

        activity('audit')->event('gdpr_erase')
            ->causedBy(auth()->user())
            ->withProperties(['identifier' => $this->identifier, 'summary' => $summary])
            ->log("Ștergere GDPR aplicată pentru {$this->identifier}: {$total} înregistrări anonimizate");

        $this->confirmText = '';
        $this->search(); // reîmprospătează (acum anonimizate)

        Notification::make()->title("Anonimizat: {$total} înregistrări")->success()->send();
    }
}
