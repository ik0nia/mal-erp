<?php

namespace App\Filament\App\Pages;

use App\Jobs\GenerateBiAnalysisJob;
use App\Models\BiAnalysis;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class BiAnalysisPage extends Page
{
    protected static string $view = 'filament.app.pages.bi-analysis';

    protected static ?string $navigationLabel = 'Analiză BI';
    protected static ?string $navigationGroup = 'Rapoarte';
    protected static ?string $navigationIcon  = 'heroicon-o-chart-bar';
    protected static ?int    $navigationSort  = 99;
    protected static ?string $title           = 'Analiză Business Intelligence';

    public ?int $pendingId  = null;
    public ?int $selectedId = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public function mount(): void
    {
        BiAnalysis::where('status', 'pending')
            ->where('generated_at', '<', now()->subMinutes(15))
            ->update(['status' => 'failed', 'error_message' => 'Job timeout — nu a răspuns în 15 minute.']);

        $pending = BiAnalysis::where('status', 'pending')->latest()->first();
        $this->pendingId = $pending?->id;

        $latest = BiAnalysis::where('status', 'done')->latest('generated_at')->first();
        $this->selectedId = $latest?->id;
    }

    public function selectAnalysis(int $id): void
    {
        $this->selectedId = $id;
    }

    public function checkPending(): void
    {
        if (! $this->pendingId) {
            return;
        }

        $analysis = BiAnalysis::find($this->pendingId);

        if (! $analysis || $analysis->status === 'done') {
            $this->pendingId  = null;
            $this->selectedId = $analysis?->id;
            if ($analysis?->status === 'done') {
                Notification::make()->title('Analiza BI a fost generată!')->success()->send();
            }
            return;
        }

        if ($analysis->status === 'failed') {
            $this->pendingId = null;
            Notification::make()
                ->title('Eroare la generare')
                ->body($analysis->error_message)
                ->danger()
                ->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generate')
                ->label('Generează analiză nouă')
                ->icon('heroicon-o-sparkles')
                ->color('primary')
                ->disabled(fn () => $this->pendingId !== null)
                ->modalHeading('Configurează analiza BI')
                ->modalDescription('Alege ce secțiuni și ce perioadă să analizeze Claude. Raportul rulează în fundal și apare automat când e gata.')
                ->modalSubmitActionLabel('Generează')
                ->modalWidth('lg')
                ->form([
                    Forms\Components\Select::make('sections')
                        ->label('Secțiuni de analizat')
                        ->options([
                            'both'   => 'Stocuri + Magazin online',
                            'stock'  => 'Doar stocuri și prețuri',
                            'online' => 'Doar magazin online',
                        ])
                        ->default('both')
                        ->required()
                        ->native(false),

                    Forms\Components\Select::make('period')
                        ->label('Perioadă')
                        ->options([
                            '7'      => 'Ultimele 7 zile',
                            '30'     => 'Ultimele 30 de zile',
                            '90'     => 'Ultimele 90 de zile',
                            '365'    => 'Ultimele 12 luni',
                            'day'    => 'O zi anume',
                            'custom' => 'Interval personalizat',
                        ])
                        ->default('30')
                        ->required()
                        ->native(false)
                        ->live(),

                    Forms\Components\DatePicker::make('single_day')
                        ->label('Ziua')
                        ->required()
                        ->maxDate(today())
                        ->default(today()->subDay())
                        ->displayFormat('d.m.Y')
                        ->native(false)
                        ->visible(fn (Forms\Get $get) => $get('period') === 'day'),

                    Forms\Components\Grid::make(2)
                        ->schema([
                            Forms\Components\DatePicker::make('date_from')
                                ->label('De la')
                                ->required()
                                ->maxDate(today()->subDay())
                                ->displayFormat('d.m.Y')
                                ->native(false),

                            Forms\Components\DatePicker::make('date_to')
                                ->label('Până la')
                                ->required()
                                ->maxDate(today())
                                ->displayFormat('d.m.Y')
                                ->native(false),
                        ])
                        ->visible(fn (Forms\Get $get) => $get('period') === 'custom'),
                ])
                ->action(fn (array $data) => $this->dispatchGeneration($data)),
        ];
    }

    public function dispatchGeneration(array $data): void
    {
        // Rezolvă intervalul de date
        if ($data['period'] === 'day') {
            $dateFrom = Carbon::parse($data['single_day'])->startOfDay();
            $dateTo   = Carbon::parse($data['single_day'])->endOfDay();
        } elseif ($data['period'] === 'custom') {
            $dateFrom = Carbon::parse($data['date_from'])->startOfDay();
            $dateTo   = Carbon::parse($data['date_to'])->endOfDay();
        } else {
            $dateFrom = Carbon::today()->subDays((int) $data['period'])->startOfDay();
            $dateTo   = Carbon::now();
        }

        $sections    = $data['sections'];
        $periodLabel = match($data['period']) {
            '7'      => 'Ultimele 7 zile',
            '30'     => 'Ultimele 30 de zile',
            '90'     => 'Ultimele 90 de zile',
            '365'    => 'Ultimele 12 luni',
            'day'    => $dateFrom->format('d.m.Y'),
            'custom' => $dateFrom->format('d.m.Y') . ' – ' . Carbon::parse($data['date_to'])->format('d.m.Y'),
            default  => $data['period'] . ' zile',
        };
        $sectionsLabel = match($sections) {
            'stock'  => 'Stocuri',
            'online' => 'Online',
            default  => 'Stocuri + Online',
        };

        $analysis = BiAnalysis::create([
            'generated_by' => Auth::id(),
            'title'        => "Analiză BI — {$periodLabel} — {$sectionsLabel}",
            'content'      => '',
            'status'       => 'pending',
            'generated_at' => now(),
        ]);

        GenerateBiAnalysisJob::dispatch($analysis->id, $sections, $dateFrom, $dateTo);

        $this->pendingId = $analysis->id;

        Notification::make()
            ->title('Analiză pornită!')
            ->body("Perioadă: {$periodLabel} · Secțiuni: {$sectionsLabel}. Pagina se actualizează automat.")
            ->info()
            ->send();
    }

    public function deleteAnalysis(int $id): void
    {
        BiAnalysis::find($id)?->delete();

        if ($this->selectedId === $id) {
            $this->selectedId = BiAnalysis::where('status', 'done')->latest('generated_at')->first()?->id;
        }

        Notification::make()->title('Analiză ștearsă')->success()->send();
    }

    public function getAllAnalyses()
    {
        return BiAnalysis::whereIn('status', ['done', 'failed'])
            ->latest('generated_at')
            ->get();
    }

    public function getSelectedAnalysis(): ?BiAnalysis
    {
        return $this->selectedId ? BiAnalysis::find($this->selectedId) : null;
    }
}
