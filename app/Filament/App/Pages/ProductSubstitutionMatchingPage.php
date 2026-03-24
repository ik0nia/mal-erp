<?php

namespace App\Filament\App\Pages;

use App\Filament\App\Concerns\ChecksRolePermissions;
use App\Jobs\ProposeProductSubstitutionJob;
use App\Models\ProductSubstitutionProposal;
use App\Models\WooProduct;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Actions\Action as TableAction;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProductSubstitutionMatchingPage extends Page implements HasTable
{
    use InteractsWithTable, ChecksRolePermissions;

    protected static string|\BackedEnum|null $navigationIcon  = 'heroicon-o-arrows-right-left';
    protected static string|\UnitEnum|null $navigationGroup = 'Produse';
    protected static ?string $navigationLabel = 'Matching înlocuitori Toya';
    protected static ?int    $navigationSort  = 28;
    protected string  $view            = 'filament.app.pages.product-substitution-matching';

    // ----------------------------------------------------------------
    // Stats
    // ----------------------------------------------------------------

    public function getStats(): array
    {
        $total    = ProductSubstitutionProposal::count();
        $pending  = ProductSubstitutionProposal::where('status', 'pending')->count();
        $approved = ProductSubstitutionProposal::where('status', 'approved')->count();
        $rejected = ProductSubstitutionProposal::where('status', 'rejected')->count();
        $noMatch  = ProductSubstitutionProposal::where('status', 'no_match')->count();

        $totalSource  = WooProduct::where('source', '!=', WooProduct::SOURCE_TOYA_API)->count();
        $unprocessed  = $totalSource - $total;

        return compact('total', 'pending', 'approved', 'rejected', 'noMatch', 'totalSource', 'unprocessed');
    }

    // ----------------------------------------------------------------
    // Header actions
    // ----------------------------------------------------------------

    protected function getHeaderActions(): array
    {
        return [
            Action::make('runAgents')
                ->label('Pornește agenți AI')
                ->icon('heroicon-o-sparkles')
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Pornește matching AI')
                ->modalDescription('Se vor dispatcha joburi AI pentru produsele neprocesate încă. Agenții caută automat echivalente în catalogul Toya.')
                ->modalSubmitActionLabel('Pornește')
                ->action(function (): void {
                    $processedIds = ProductSubstitutionProposal::pluck('source_product_id')->all();

                    $ids = WooProduct::where('source', '!=', WooProduct::SOURCE_TOYA_API)
                        ->whereNotNull('name')
                        ->whereNotIn('id', $processedIds)
                        ->pluck('id')
                        ->all();

                    if (empty($ids)) {
                        Notification::make()->info()->title('Toate produsele au fost deja procesate')->send();
                        return;
                    }

                    $chunks = array_chunk($ids, 5);
                    foreach ($chunks as $chunk) {
                        ProposeProductSubstitutionJob::dispatch($chunk);
                    }

                    Notification::make()
                        ->success()
                        ->title(count($chunks) . ' agenți porniti pentru ' . count($ids) . ' produse')
                        ->send();
                }),

            Action::make('applyApproved')
                ->label('Aplică aprobate')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Aplică toate propunerile aprobate')
                ->modalDescription('Se va seta câmpul "Înlocuit de" pe toate produsele cu propunere aprobată.')
                ->modalSubmitActionLabel('Aplică')
                ->action(function (): void {
                    $proposals = ProductSubstitutionProposal::where('status', 'approved')
                        ->whereNotNull('proposed_toya_id')
                        ->get();

                    $count = 0;
                    foreach ($proposals as $proposal) {
                        WooProduct::where('id', $proposal->source_product_id)
                            ->update(['substituted_by_id' => $proposal->proposed_toya_id]);
                        $count++;
                    }

                    Notification::make()
                        ->success()
                        ->title("{$count} produse actualizate cu înlocuitor Toya")
                        ->send();
                }),

            Action::make('resetProposals')
                ->label('Resetează')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Resetează toate propunerile')
                ->modalDescription('Șterge toate propunerile pending. Cele aprobate rămân.')
                ->modalSubmitActionLabel('Resetează')
                ->action(function (): void {
                    $deleted = ProductSubstitutionProposal::whereIn('status', ['pending', 'no_match', 'rejected'])->delete();
                    Notification::make()->success()->title("{$deleted} propuneri șterse")->send();
                }),
        ];
    }

    // ----------------------------------------------------------------
    // Table
    // ----------------------------------------------------------------

    public function table(Table $table): Table
    {
        return $table
            ->query(
                ProductSubstitutionProposal::query()
                    ->with(['sourceProduct.suppliers', 'proposedToya'])
                    ->latest()
            )
            ->columns([
                TextColumn::make('sourceProduct.name')
                    ->label('Produs existent')
                    ->searchable(query: fn (Builder $q, string $s) => $q->whereHas('sourceProduct',
                        fn ($q) => $q->where('name', 'like', "%{$s}%")->orWhere('sku', 'like', "%{$s}%")
                    ))
                    ->wrap()
                    ->limit(50)
                    ->description(function (ProductSubstitutionProposal $r): \Illuminate\Support\HtmlString {
                        $parts = [];
                        if ($r->sourceProduct?->sku) {
                            $parts[] = '<span class="text-xs text-gray-400">' . e($r->sourceProduct->sku) . '</span>';
                        }
                        if ($r->sourceProduct?->suppliers?->count()) {
                            $names = e($r->sourceProduct->suppliers->pluck('name')->implode(', '));
                            $parts[] = '<span style="margin-left:6px;display:inline-flex;align-items:center;padding:1px 8px;border-radius:9999px;font-size:0.7rem;font-weight:700;background:#7c3aed;color:#fff;">' . $names . '</span>';
                        }
                        return new \Illuminate\Support\HtmlString(implode('', $parts));
                    })
                    ->url(fn (ProductSubstitutionProposal $r) => $r->source_product_id
                        ? \App\Filament\App\Resources\WooProductResource::getUrl('view', ['record' => $r->source_product_id])
                        : null)
                    ->openUrlInNewTab(),

                TextColumn::make('sourceProduct.source')
                    ->label('Sursă')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'woocommerce'   => 'WooCommerce',
                        'winmentor_csv' => 'WinMentor',
                        default         => $state,
                    })
                    ->color(fn ($state) => match ($state) {
                        'woocommerce'   => 'info',
                        'winmentor_csv' => 'warning',
                        default         => 'gray',
                    }),

                TextColumn::make('proposedToya.name')
                    ->label('Propunere Toya')
                    ->wrap()
                    ->limit(50)
                    ->placeholder('—')
                    ->description(fn (ProductSubstitutionProposal $r) => $r->proposedToya
                        ? ($r->proposedToya->sku . ' · ' . ($r->proposedToya->brand ?? ''))
                        : null)
                    ->url(fn (ProductSubstitutionProposal $r) => $r->proposed_toya_id
                        ? \App\Filament\App\Resources\WooProductResource::getUrl('view', ['record' => $r->proposed_toya_id])
                        : null)
                    ->openUrlInNewTab(),

                TextColumn::make('confidence')
                    ->label('Încredere')
                    ->formatStateUsing(fn ($state) => $state ? round($state * 100) . '%' : '—')
                    ->badge()
                    ->color(fn ($state) => match (true) {
                        $state >= 0.85 => 'success',
                        $state >= 0.65 => 'warning',
                        default        => 'danger',
                    }),

                TextColumn::make('reasoning')
                    ->label('Motivare AI')
                    ->wrap()
                    ->limit(80)
                    ->placeholder('—'),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'pending'  => 'În așteptare',
                        'approved' => 'Aprobat',
                        'rejected' => 'Respins',
                        'no_match' => 'Fără match',
                        default    => $state,
                    })
                    ->color(fn ($state) => match ($state) {
                        'pending'  => 'warning',
                        'approved' => 'success',
                        'rejected' => 'danger',
                        'no_match' => 'gray',
                        default    => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'pending'  => 'În așteptare',
                        'approved' => 'Aprobat',
                        'rejected' => 'Respins',
                        'no_match' => 'Fără match',
                    ])
                    ->default('pending'),

                SelectFilter::make('source')
                    ->label('Sursă produs')
                    ->options([
                        'woocommerce'   => 'WooCommerce',
                        'winmentor_csv' => 'WinMentor',
                    ])
                    ->query(fn (Builder $q, array $data) => $data['value']
                        ? $q->whereHas('sourceProduct', fn ($q) => $q->where('source', $data['value']))
                        : $q),
            ])
            ->deferFilters(false)
            ->recordActions([
                TableAction::make('compare')
                    ->label('Vizualizează')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading('Comparare produse')
                    ->modalWidth('5xl')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Închide')
                    ->modalContent(fn (ProductSubstitutionProposal $record) => view(
                        'filament.app.pages.product-compare-modal',
                        [
                            'source'   => $record->sourceProduct?->load('suppliers'),
                            'proposed' => $record->proposedToya,
                            'record'   => $record,
                        ]
                    )),

                TableAction::make('approve')
                    ->label('Aprobă')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (ProductSubstitutionProposal $r) => $r->status === 'pending' && $r->proposed_toya_id)
                    ->action(function (ProductSubstitutionProposal $record): void {
                        $record->update([
                            'status'      => 'approved',
                            'approved_by' => auth()->id(),
                            'approved_at' => now(),
                        ]);
                        Notification::make()->success()->title('Propunere aprobată')->send();
                    }),

                TableAction::make('change')
                    ->label('Schimbă')
                    ->icon('heroicon-o-pencil')
                    ->color('info')
                    ->visible(fn (ProductSubstitutionProposal $r) => in_array($r->status, ['pending', 'rejected']))
                    ->form([
                        Forms\Components\Select::make('proposed_toya_id')
                            ->label('Produs Toya înlocuitor')
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search) => WooProduct::query()
                                ->where('source', WooProduct::SOURCE_TOYA_API)
                                ->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%"))
                                ->limit(20)
                                ->get()
                                ->mapWithKeys(fn ($p) => [$p->id => "[{$p->sku}] {$p->name}"]))
                            ->required()
                            ->native(false),
                    ])
                    ->action(function (ProductSubstitutionProposal $record, array $data): void {
                        $record->update([
                            'proposed_toya_id' => $data['proposed_toya_id'],
                            'status'           => 'approved',
                            'confidence'       => 1.0,
                            'reasoning'        => 'Selectat manual.',
                            'approved_by'      => auth()->id(),
                            'approved_at'      => now(),
                        ]);
                        Notification::make()->success()->title('Înlocuitor setat și aprobat')->send();
                    }),

                TableAction::make('reject')
                    ->label('Respinge')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->visible(fn (ProductSubstitutionProposal $r) => $r->status === 'pending')
                    ->requiresConfirmation()
                    ->action(function (ProductSubstitutionProposal $record): void {
                        $record->update(['status' => 'rejected']);
                        Notification::make()->success()->title('Propunere respinsă')->send();
                    }),
            ])
            ->bulkActions([
                BulkAction::make('bulk_approve')
                    ->label('Aprobă selecția')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->action(function (Collection $records): void {
                        $count = 0;
                        foreach ($records as $record) {
                            if ($record->status === 'pending' && $record->proposed_toya_id) {
                                $record->update([
                                    'status'      => 'approved',
                                    'approved_by' => auth()->id(),
                                    'approved_at' => now(),
                                ]);
                                $count++;
                            }
                        }
                        Notification::make()->success()->title("{$count} propuneri aprobate")->send();
                    }),

                BulkAction::make('bulk_reject')
                    ->label('Respinge selecția')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (Collection $records): void {
                        foreach ($records as $record) {
                            if ($record->status === 'pending') {
                                $record->update(['status' => 'rejected']);
                            }
                        }
                        Notification::make()->success()->title('Propuneri respinse')->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->striped()
            ->paginated([25, 50, 100]);
    }
}
