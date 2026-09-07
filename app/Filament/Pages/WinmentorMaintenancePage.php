<?php

namespace App\Filament\Pages;

use App\Services\Winmentor\WinmentorBridgeClient;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class WinmentorMaintenancePage extends Page
{
    protected static string|\BackedEnum|null $navigationIcon  = 'heroicon-o-power';
    protected static ?string $navigationLabel = 'Mentenanță COM WinMentor';
    protected static ?string $title           = 'Mentenanță WinMentor (conexiune COM)';
    protected static string|\UnitEnum|null $navigationGroup = 'Integrări';
    protected static ?int    $navigationSort  = 40;
    protected string $view = 'filament.pages.winmentor-maintenance';

    /** Utilizatori cu drept de conectare/deconectare COM (pe lângă super_admin). */
    private const ALLOWED_EMAILS = [
        'eli@malinco.ro',
        'calin@malinco.ro',
        'ioana@malinco.ro',
        'mariana@malinco.ro',
        'teo@malinco.ro',
    ];

    public const CONNECTING_CACHE_KEY = 'winmentor_com_connecting';

    public ?array $health = null;
    public bool $reachable = false;
    public bool $connecting = false;

    public static function canAccess(): bool
    {
        $user = auth()->user();
        if (! $user instanceof \App\Models\User) return false;

        return $user->isSuperAdmin()
            || in_array(strtolower($user->email), self::ALLOWED_EMAILS, true);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->refreshHealth();
    }

    public function refreshHealth(): void
    {
        try {
            $result = app(WinmentorBridgeClient::class)->health();
            $this->health    = $result['data'] ?? null;
            $this->reachable = ($result['success'] ?? false) === true;
        } catch (\Throwable $e) {
            $this->health    = null;
            $this->reachable = false;
        }

        $this->connecting = cache()->has(self::CONNECTING_CACHE_KEY) && ! $this->isComConnected();
    }

    public function isComConnected(): bool
    {
        return ($this->health['comConnected'] ?? false) === true;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Reîmprospătează')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(fn () => $this->refreshHealth()),

            Action::make('disconnect')
                ->label('Deconectează COM')
                ->icon('heroicon-o-pause-circle')
                ->color('danger')
                ->visible(fn () => $this->reachable && $this->isComConnected())
                ->requiresConfirmation()
                ->modalHeading('Deconectezi COM-ul de la WinMentor?')
                ->modalDescription('Cât timp e deconectat, sincronizările ERP ↔ WinMentor sunt oprite (serverul intră în mod mentenanță). Folosește asta doar pentru închiderea de lună sau operațiuni care cer toți utilizatorii deconectați din Mentor. Nu uita să reconectezi la final!')
                ->modalSubmitActionLabel('Da, deconectează')
                ->action(function () {
                    try {
                        $result = app(WinmentorBridgeClient::class)->comDisconnect();

                        if (($result['success'] ?? false) === true) {
                            \Log::info('WinMentor COM deconectat manual', ['user' => auth()->user()?->email]);
                            Notification::make()
                                ->title('COM deconectat')
                                ->body('MentorAPI e în mod mentenanță. Sincronizările sunt oprite până la reconectare.')
                                ->warning()->send();
                        } else {
                            Notification::make()
                                ->title('Deconectarea a eșuat')
                                ->body(implode('; ', $result['errors'] ?? ['Eroare necunoscută']))
                                ->danger()->send();
                        }
                    } catch (\Throwable $e) {
                        Notification::make()->title('Eroare')->body($e->getMessage())->danger()->send();
                    }

                    $this->refreshHealth();
                }),

            Action::make('connect')
                ->label('Reconectează COM')
                ->icon('heroicon-o-play-circle')
                ->color('success')
                ->visible(fn () => $this->reachable && ! $this->isComConnected() && ! $this->connecting)
                ->requiresConfirmation()
                ->modalHeading('Reconectezi COM-ul la WinMentor?')
                ->modalDescription('Reconectarea durează de obicei în jur de 1 minut (WinMentor face logon complet). Statusul de pe pagină se actualizează singur când e gata.')
                ->modalSubmitActionLabel('Da, reconectează')
                ->action(function () {
                    cache()->put(self::CONNECTING_CACHE_KEY, true, 180);
                    \App\Jobs\WinmentorComConnectJob::dispatch(auth()->user()?->email);

                    Notification::make()
                        ->title('Reconectare pornită')
                        ->body('Durează de obicei ~1 minut. Statusul se actualizează automat — nu mai apăsa încă o dată.')
                        ->info()
                        ->duration(15000)
                        ->send();

                    $this->refreshHealth();
                }),
        ];
    }
}
