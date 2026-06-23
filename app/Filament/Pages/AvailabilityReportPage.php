<?php

namespace App\Filament\Pages;

use App\Models\UptimeProbe;
use App\Models\User;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;

class AvailabilityReportPage extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-signal';

    protected static string|\UnitEnum|null $navigationGroup = 'Conformitate';

    protected static ?string $navigationLabel = 'Disponibilitate & SLA';

    protected static ?int $navigationSort = 20;

    protected string $view = 'filament.pages.availability-report';

    public string $month;

    public function mount(): void
    {
        $this->month = now()->format('Y-m');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isSuperAdmin();
    }

    public function getMonthOptions(): array
    {
        $options = [];
        for ($i = 0; $i < 12; $i++) {
            $d = now()->startOfMonth()->subMonths($i);
            $options[$d->format('Y-m')] = $d->translatedFormat('F Y');
        }

        return $options;
    }

    /** Pragurile de credite de serviciu din contract (Art. 8.9), aplicabile țintei 'app'. */
    public static function creditFor(float $pct): array
    {
        return match (true) {
            $pct >= 99.0 => ['credit' => 0,  'label' => 'Conform SLA (≥ 99%)', 'color' => 'success'],
            $pct >= 97.0 => ['credit' => 5,  'label' => 'Credit 5% (sub 99%)',  'color' => 'warning'],
            $pct >= 95.0 => ['credit' => 10, 'label' => 'Credit 10% (sub 97%)', 'color' => 'warning'],
            default      => ['credit' => 20, 'label' => 'Credit 20% (sub 95%)', 'color' => 'danger'],
        };
    }

    public function getReport(): array
    {
        [$y, $m] = explode('-', $this->month);
        $from = Carbon::create((int) $y, (int) $m, 1)->startOfMonth();
        $to = $from->copy()->endOfMonth();

        $report = [];
        foreach (UptimeProbe::TARGETS as $target => $label) {
            $q = UptimeProbe::where('target', $target)
                ->whereBetween('checked_at', [$from, $to]);

            $total = (clone $q)->count();
            $up = (clone $q)->where('is_up', true)->count();
            $pct = $total > 0 ? round($up / $total * 100, 3) : null;
            $avgMs = (clone $q)->where('is_up', true)->avg('response_ms');
            $down = (clone $q)->where('is_up', false)->count();

            $report[$target] = [
                'label'      => $label,
                'total'      => $total,
                'up'         => $up,
                'down'       => $down,
                'pct'        => $pct,
                'avg_ms'     => $avgMs ? (int) round($avgMs) : null,
                'credit'     => ($target === 'app' && $pct !== null) ? static::creditFor($pct) : null,
                'incidents'  => (clone $q)->where('is_up', false)
                    ->orderByDesc('checked_at')->limit(10)
                    ->get(['checked_at', 'response_ms', 'error']),
            ];
        }

        return $report;
    }
}
