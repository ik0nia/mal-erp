<?php

namespace App\Filament\App\Widgets;

use App\Filament\Pages\WinmentorMaintenancePage;
use App\Services\Winmentor\WinmentorBridgeClient;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;

/**
 * Widget de dashboard (panel-ul principal „/”) pentru mentenanța COM WinMentor.
 * Aceleași operațiuni ca pagina WinmentorMaintenancePage (deconectează / reconectează COM),
 * cu confirmare pe modal Filament. Vizibil DOAR utilizatorilor cu drept.
 */
class WinmentorMaintenanceWidget extends Widget implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;

    protected string $view = 'filament.widgets.winmentor-maintenance';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = -50; // sus de tot pe dashboard

    protected static bool $isLazy = false; // fără flash de cutie goală, e important

    public ?array $health = null;
    public bool $reachable = false;
    public bool $connecting = false;
    public ?array $versiuni = null;

    public static function canView(): bool
    {
        return WinmentorMaintenancePage::canAccess();
    }

    public function mount(): void
    {
        $this->refreshHealth();
    }

    public function refreshHealth(): void
    {
        try {
            $result = app(WinmentorBridgeClient::class)->health();
            $this->health    = $result['data'] ?? null;
            $this->reachable = ($result['success'] ?? false) === true;
        } catch (\Throwable) {
            $this->health    = null;
            $this->reachable = false;
        }

        $this->connecting = cache()->has(WinmentorMaintenancePage::CONNECTING_CACHE_KEY) && ! $this->isComConnected();

        if ($this->isComConnected()) {
            try {
                $this->versiuni = cache()->remember('winmentor_versiuni', 3600, function (): array {
                    $v = app(WinmentorBridgeClient::class)->getVersiuni();

                    return [
                        'mentor' => WinmentorBridgeClient::formatVersiuneWinmentor($v['verMentor'] ?? null),
                        'server' => WinmentorBridgeClient::formatVersiuneWinmentor($v['verServer'] ?? null),
                    ];
                });
            } catch (\Throwable) {
                $this->versiuni = null;
            }
        }
    }

    public function isComConnected(): bool
    {
        return ($this->health['comConnected'] ?? false) === true;
    }

    /** Ultima versiune WinMENTOR publicată (din DB, populat de winmentor:check-latest-version). */
    public function latestRelease(): ?array
    {
        $json = \App\Models\AppSetting::get(\App\Console\Commands\CheckWinmentorLatestVersionCommand::CACHE_KEY);
        return $json ? json_decode($json, true) : null;
    }

    /**
     * Compară versiunea instalată (Mentor.exe) cu ultima publicată.
     * @return array{available:bool, latest:?string, installed:?string, date:?string}
     */
    public function updateInfo(): array
    {
        $latest    = $this->latestRelease();
        $installed = $this->versiuni['mentor'] ?? null;

        if (! $latest || ! $installed) {
            return ['available' => false, 'latest' => $latest['version'] ?? null, 'installed' => $installed, 'date' => $latest['date'] ?? null];
        }

        $cmp = \App\Console\Commands\CheckWinmentorLatestVersionCommand::score(...);

        return [
            'available' => $cmp($latest['version']) > $cmp($installed),
            'latest'    => $latest['version'],
            'installed' => $installed,
            'date'      => $latest['date'] ?? null,
        ];
    }

    /** Ultimele update-uri reale aplicate pe server (schimbări de versiune, nu baseline-uri). */
    public function versionHistory(int $limit = 6)
    {
        return \App\Models\WinmentorVersionHistory::whereNotNull('previous_version')
            ->latest('detected_at')
            ->limit($limit)
            ->get();
    }

    /** Ultimul update WinMentor (Mentor.exe) aplicat, sau null dacă doar baseline. */
    public function lastMentorUpdate(): ?\App\Models\WinmentorVersionHistory
    {
        return \App\Models\WinmentorVersionHistory::where('component', 'mentor')
            ->whereNotNull('previous_version')
            ->latest('detected_at')
            ->first();
    }

    /** Uptime prietenos: „212h57m57.5s” → „8z 20h 57m”. */
    public function uptimeHuman(): string
    {
        $raw = $this->health['uptime'] ?? null;
        if (! $raw) {
            return '—';
        }

        preg_match('/(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)(?:\.\d+)?s)?/', $raw, $m);
        $h = (int) ($m[1] ?? 0);
        $min = (int) ($m[2] ?? 0);
        $days = intdiv($h, 24);
        $h = $h % 24;

        $parts = [];
        if ($days > 0) $parts[] = $days . 'z';
        if ($h > 0)    $parts[] = $h . 'h';
        if ($min > 0 && $days === 0) $parts[] = $min . 'm';

        return $parts ? implode(' ', $parts) : '<1m';
    }

    public function refreshAction(): Action
    {
        return Action::make('refresh')
            ->label('Reîmprospătează')
            ->icon('heroicon-o-arrow-path')
            ->color('gray')
            ->outlined()
            ->size('sm')
            ->action(fn () => $this->refreshHealth());
    }

    public function disconnectAction(): Action
    {
        return Action::make('disconnect')
            ->label('Oprește (deconectează)')
            ->icon('heroicon-o-pause-circle')
            ->color('danger')
            ->size('sm')
            ->visible(fn () => $this->reachable && $this->isComConnected())
            ->requiresConfirmation()
            ->modalIcon('heroicon-o-pause-circle')
            ->modalIconColor('danger')
            ->modalHeading('Oprești legătura cu WinMentor?')
            ->modalDescription('Cât timp e deconectat, sincronizările ERP ↔ WinMentor sunt oprite (serverul intră în mod mentenanță). Folosește asta doar pentru închiderea de lună sau operațiuni care cer toți utilizatorii deconectați din Mentor. Nu uita să reconectezi la final!')
            ->modalSubmitActionLabel('Da, deconectează')
            ->action(function () {
                try {
                    $result = app(WinmentorBridgeClient::class)->comDisconnect();

                    if (($result['success'] ?? false) === true) {
                        \Log::info('WinMentor COM deconectat manual', ['user' => auth()->user()?->email, 'from' => 'dashboard-widget']);
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
            });
    }

    public function connectAction(): Action
    {
        return Action::make('connect')
            ->label('Pornește (reconectează)')
            ->icon('heroicon-o-play-circle')
            ->color('success')
            ->size('sm')
            ->visible(fn () => $this->reachable && ! $this->isComConnected() && ! $this->connecting)
            ->requiresConfirmation()
            ->modalIcon('heroicon-o-play-circle')
            ->modalIconColor('success')
            ->modalHeading('Pornești legătura cu WinMentor?')
            ->modalDescription('Reconectarea durează de obicei în jur de 1 minut (WinMentor face logon complet). Statusul se actualizează singur când e gata.')
            ->modalSubmitActionLabel('Da, reconectează')
            ->action(function () {
                cache()->put(WinmentorMaintenancePage::CONNECTING_CACHE_KEY, true, 180);
                \App\Jobs\WinmentorComConnectJob::dispatch(auth()->user()?->email);

                Notification::make()
                    ->title('Reconectare pornită')
                    ->body('Durează de obicei ~1 minut. Statusul se actualizează automat — nu mai apăsa încă o dată.')
                    ->info()
                    ->duration(15000)
                    ->send();

                $this->refreshHealth();
            });
    }
}
