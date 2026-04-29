<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Support\Facades\File;

class WinmentorBridgeLogPage extends Page
{
    protected static string|\BackedEnum|null $navigationIcon  = 'heroicon-o-arrow-path-rounded-square';
    protected static ?string $navigationLabel = 'Log WinMentor Bridge';
    protected static string|\UnitEnum|null $navigationGroup = 'Sistem';
    protected static ?int    $navigationSort  = 25;
    protected string  $view = 'filament.pages.winmentor-bridge-log';

    public string $date = '';
    public string $level = 'all';

    public function mount(): void
    {
        $this->date = now()->format('Y-m-d');
    }

    public function getAvailableDates(): array
    {
        $logPath = storage_path('logs');
        $dates   = [];

        foreach (File::glob($logPath . '/laravel-*.log') as $file) {
            if (preg_match('/laravel-(\d{4}-\d{2}-\d{2})\.log$/', $file, $m)) {
                $dates[$m[1]] = $m[1];
            }
        }

        krsort($dates);
        return $dates;
    }

    public function getLogs(): array
    {
        $logFile = storage_path("logs/laravel-{$this->date}.log");

        if (! File::exists($logFile)) {
            return [];
        }

        $lines   = File::lines($logFile);
        $entries = [];
        $current = null;

        foreach ($lines as $line) {
            // Linie nouă de log Laravel: [2026-04-04 12:34:56] ...
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] \w+\.(\w+): (.+)$/', $line, $m)) {
                if ($current !== null) {
                    $entries[] = $current;
                }
                $current = [
                    'ts'      => $m[1],
                    'level'   => strtolower($m[2]),
                    'message' => $m[3],
                    'context' => '',
                    'is_bridge' => str_contains($m[3], '[WinMentor Bridge]'),
                ];
            } elseif ($current !== null) {
                // Context JSON pe linii multiple
                $current['context'] .= $line;
            }
        }

        if ($current !== null) {
            $entries[] = $current;
        }

        // Filtrăm doar intrările bridge
        $entries = array_filter($entries, fn ($e) => $e['is_bridge']);

        // Filtru nivel
        if ($this->level !== 'all') {
            $entries = array_filter($entries, fn ($e) => $e['level'] === $this->level);
        }

        // Cele mai recente primele
        return array_values(array_reverse($entries));
    }

    public function getStats(): array
    {
        $all    = $this->getLogs();
        $errors = array_filter($all, fn ($e) => $e['level'] === 'error');
        $posts  = array_filter($all, fn ($e) => str_contains($e['message'], '] POST '));
        $gets   = array_filter($all, fn ($e) => str_contains($e['message'], '] GET '));
        $resp   = array_filter($all, fn ($e) => str_contains($e['message'], '] Response '));

        return [
            'total'  => count($all),
            'errors' => count($errors),
            'gets'   => count($gets),
            'posts'  => count($posts),
            'resp'   => count($resp),
        ];
    }
}
