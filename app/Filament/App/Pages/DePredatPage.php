<?php

namespace App\Filament\App\Pages;

use App\Services\Winmentor\VanzariAziService;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;

class DePredatPage extends Page
{
    protected string $view = 'filament.app.pages.de-predat';

    protected static ?string $navigationLabel = 'De predat (depozit)';
    protected static string|\UnitEnum|null $navigationGroup = 'WinMentor';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-truck';
    protected static ?int $navigationSort = 93;
    protected static ?string $title = 'De predat / de încărcat';

    #[Url] public string $sursa = '';
    public string $zi = '';

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::canAccess()) return null;
        $n = count(app(VanzariAziService::class)->dePredat());
        return $n > 0 ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public function mount(): void
    {
        $this->zi = Carbon::now('Europe/Bucharest')->toDateString();
    }

    protected function getViewData(): array
    {
        return [
            'documente' => app(VanzariAziService::class)->dePredat($this->zi, $this->sursa ?: null),
            'sursaSel'  => $this->sursa,
        ];
    }

    public function marcheazaPredat(string $tipDoc, string $docId, string $sursa): void
    {
        app(VanzariAziService::class)->marcheazaPredat($tipDoc, $docId, $sursa, auth()->id());

        \Filament\Notifications\Notification::make()
            ->title('Marcat ca predat / încărcat')
            ->success()
            ->send();
    }

    /** Predă tot restul unui produs (o alocare). */
    public function marcheazaLinie(string $tipDoc, string $docId, string $pozitie, string $sursa): void
    {
        app(VanzariAziService::class)->marcheazaLiniePredat($tipDoc, $docId, $pozitie, $sursa, auth()->id());
    }

    /** Predare parțială pe cantitate. */
    public function predaPartial(string $tipDoc, string $docId, string $pozitie, string $sursa, float $cant): void
    {
        if ($cant > 0) {
            app(VanzariAziService::class)->predaCantitate($tipDoc, $docId, $pozitie, $sursa, $cant, auth()->id());
        }
    }
}
