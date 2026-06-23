<?php

namespace App\Filament\App\Pages;

use App\Services\Winmentor\VanzariAziService;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;

class DispecerVanzariPage extends Page
{
    protected string $view = 'filament.app.pages.dispecer-vanzari';

    protected static ?string $navigationLabel = 'Dispecerizare vânzări';
    protected static string|\UnitEnum|null $navigationGroup = 'WinMentor';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-group';
    protected static ?int $navigationSort = 92;
    protected static ?string $title = 'Dispecerizare vânzări — azi';

    #[Url] public string $tip = '';
    #[Url] public string $filtru = '';
    #[Url] public string $search = '';
    public string $zi = '';

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        $this->zi = Carbon::now('Europe/Bucharest')->toDateString();
    }

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('refresh')
                ->label('Reîmprospătează')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(fn () => app(VanzariAziService::class)->reimprospateaza($this->zi)),
        ];
    }

    protected function getViewData(): array
    {
        $data = app(VanzariAziService::class)->pagina($this->zi, $this->tip, $this->filtru, $this->search);

        return array_merge($data, [
            'tipSel'    => $this->tip,
            'filtruSel' => $this->filtru,
        ]);
    }

    /** Pune toată cantitatea liniei pe o singură sursă. */
    public function setQuick(string $tipDoc, string $docId, string $pozitie, string $sursa, float $total): void
    {
        app(VanzariAziService::class)->salveazaAlocari($tipDoc, $docId, $pozitie, [$sursa => $total], $this->zi, auth()->id());
    }

    /** Împarte cantitatea liniei pe surse. */
    public function salveazaSplit(string $tipDoc, string $docId, string $pozitie, float $magazin, float $depozit, float $livrare): void
    {
        app(VanzariAziService::class)->salveazaAlocari(
            $tipDoc, $docId, $pozitie,
            ['magazin' => $magazin, 'depozit' => $depozit, 'livrare' => $livrare],
            $this->zi, auth()->id()
        );
    }

    public function confirmaDoc(string $tipDoc, string $docId): void
    {
        $n = app(VanzariAziService::class)->confirmaDocument($tipDoc, $docId, $this->zi, auth()->id());

        \Filament\Notifications\Notification::make()
            ->title($n > 0 ? 'Confirmat' : 'Nimic de confirmat (alege întâi sursa)')
            ->body($n > 0 ? 'Magazinul e gata; depozitul/livrarea au fost trimise la predare.' : '')
            ->{$n > 0 ? 'success' : 'warning'}()
            ->send();
    }
}
