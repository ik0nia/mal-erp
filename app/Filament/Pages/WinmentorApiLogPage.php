<?php

namespace App\Filament\Pages;

use App\Models\WinmentorApiLog;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;

class WinmentorApiLogPage extends Page
{
    protected static string|\BackedEnum|null $navigationIcon  = 'heroicon-o-signal';
    protected static ?string $navigationLabel = 'Log MentorAPI';
    protected static ?string $title           = 'Log MentorAPI — apeluri API';
    protected static string|\UnitEnum|null $navigationGroup = 'Integrări';
    protected static ?int    $navigationSort  = 60;
    protected string $view = 'filament.pages.winmentor-api-log';

    public string $date   = '';
    public string $method = 'all';   // all | GET | POST
    public string $status = 'all';   // all | ok | error
    public string $search = '';
    public int    $limit  = 300;

    public static function canAccess(): bool
    {
        $user = auth()->user();
        if (! $user instanceof \App\Models\User) return false;
        return $user->isSuperAdmin() || $user->isAdmin();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        $this->date = now()->format('Y-m-d');
    }

    /** Zilele care au apeluri, cele mai noi primele. */
    public function getAvailableDates(): array
    {
        return WinmentorApiLog::query()
            ->selectRaw('DATE(created_at) as d')
            ->groupBy('d')
            ->orderByDesc('d')
            ->limit(60)
            ->pluck('d')
            ->map(fn ($d) => (string) $d)
            ->all();
    }

    /** Query de bază după data + filtrele curente. */
    protected function baseQuery(): Builder
    {
        return WinmentorApiLog::query()
            ->when($this->date !== '', fn ($q) => $q->whereDate('created_at', $this->date))
            ->when($this->method !== 'all', fn ($q) => $q->where('method', $this->method))
            ->when($this->status === 'ok', fn ($q) => $q->where('success', true))
            ->when($this->status === 'error', fn ($q) => $q->where('success', false))
            ->when($this->search !== '', fn ($q) => $q->where('endpoint', 'like', '%' . $this->search . '%'));
    }

    public function getStats(): array
    {
        $q = $this->baseQuery();

        return [
            'total'  => (clone $q)->count(),
            'gets'   => (clone $q)->where('method', 'GET')->count(),
            'posts'  => (clone $q)->whereIn('method', ['POST', 'PUT'])->count(),
            'ok'     => (clone $q)->where('success', true)->count(),
            'errors' => (clone $q)->where('success', false)->count(),
            'avg_ms' => (int) round((clone $q)->avg('duration_ms') ?? 0),
        ];
    }

    /** @return \Illuminate\Support\Collection<int,WinmentorApiLog> */
    public function getLogs()
    {
        return $this->baseQuery()
            ->latest('created_at')
            ->limit($this->limit)
            ->get();
    }
}
