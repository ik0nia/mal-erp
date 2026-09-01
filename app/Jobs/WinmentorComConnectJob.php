<?php

namespace App\Jobs;

use App\Services\Winmentor\WinmentorBridgeClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class WinmentorComConnectJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    // conectarea COM după disconnect durează ~60s; fără retry ca să nu
    // suprapunem două logon-uri simultane pe același obiect COM
    public int $timeout = 200;
    public int $tries = 1;

    public function __construct(public ?string $triggeredBy = null)
    {
    }

    public function handle(): void
    {
        try {
            $result = app(WinmentorBridgeClient::class)->comConnect();

            if (($result['data']['comConnected'] ?? false) === true) {
                \Log::info('WinMentor COM reconectat manual', ['user' => $this->triggeredBy]);
            } else {
                \Log::error('WinMentor COM reconectare eșuată', [
                    'user'   => $this->triggeredBy,
                    'errors' => $result['errors'] ?? [],
                ]);
            }
        } finally {
            cache()->forget(\App\Filament\App\Pages\WinmentorMaintenancePage::CONNECTING_CACHE_KEY);
        }
    }

    public function failed(\Throwable $e): void
    {
        cache()->forget(\App\Filament\App\Pages\WinmentorMaintenancePage::CONNECTING_CACHE_KEY);
        \Log::error('WinMentor COM reconectare eșuată (exception)', [
            'user'  => $this->triggeredBy,
            'error' => $e->getMessage(),
        ]);
    }
}
