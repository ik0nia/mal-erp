<?php

namespace App\Filament\App\Pages;

use App\Filament\App\Concerns\ChecksRolePermissions;
use App\Jobs\ProposeToyaCategoryMappingJob;
use App\Models\IntegrationConnection;
use App\Models\ToyaCategoryProposal;
use App\Models\WooCategory;
use App\Models\WooProduct;
use App\Services\WooCommerce\WooClient;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Actions\Action as TableAction;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ToyaCategoryMappingPage extends Page implements HasTable
{
    use InteractsWithTable, ChecksRolePermissions;

    protected static string|\BackedEnum|null $navigationIcon  = 'heroicon-o-tag';
    protected static string|\UnitEnum|null $navigationGroup = 'Produse';
    protected static ?string $navigationLabel = 'Categorii Toya';
    protected static ?int    $navigationSort  = 27;
    protected string  $view            = 'filament.app.pages.toya-category-mapping';

    // ----------------------------------------------------------------
    // Header actions
    // ----------------------------------------------------------------

    protected function getHeaderActions(): array
    {
        return [
            Action::make('run_agents')
                ->label('Pornește 15 agenți AI')
                ->icon('heroicon-o-sparkles')
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Pornești 15 agenți AI?')
                ->modalDescription('Se vor trimite 15 job-uri în queue. Fiecare agent analizează ~76 categorii Toya și propune maparea la categoriile WooCommerce existente. Durată estimată: 2-5 minute.')
                ->modalSubmitActionLabel('Da, pornește agenții')
                ->action(function () {
                    $result = $this->dispatchAgents(30);
                    Notification::make()
                        ->title("{$result['chunks']} agenți AI porniți")
                        ->body("{$result['paths']} path-uri Toya împărțite în {$result['chunks']} chunk-uri. Reîncarcă pagina în câteva minute.")
                        ->success()
                        ->send();
                }),

            Action::make('apply_approved')
                ->label('Aplică aprobate')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Aplică toate mapările aprobate?')
                ->modalDescription('Se vor asocia produsele Toya la categoriile WooCommerce aprobate. Operația este reversibilă.')
                ->modalSubmitActionLabel('Da, aplică')
                ->action(function () {
                    $count = $this->applyApprovedMappings();
                    Notification::make()
                        ->title("{$count} produse actualizate")
                        ->body('Categoriile au fost asociate cu succes.')
                        ->success()
                        ->send();
                }),

            Action::make('reset_proposals')
                ->label('Resetează propunerile')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Ștergi toate propunerile?')
                ->modalDescription('Toate propunerile (inclusiv cele aprobate) vor fi șterse. Asocierile deja aplicate pe produse NU se șterg.')
                ->action(function () {
                    ToyaCategoryProposal::truncate();
                    Notification::make()->title('Propunerile au fost șterse')->warning()->send();
                }),
        ];
    }

    // ----------------------------------------------------------------
    // Stats pentru view
    // ----------------------------------------------------------------

    public function getStats(): array
    {
        return [
            'total'    => ToyaCategoryProposal::count(),
            'pending'  => ToyaCategoryProposal::where('status', 'pending')->count(),
            'approved' => ToyaCategoryProposal::where('status', 'approved')->count(),
            'rejected' => ToyaCategoryProposal::where('status', 'rejected')->count(),
            'no_match' => ToyaCategoryProposal::where('status', 'no_match')->count(),
            'products_with_cat' => WooProduct::where('source', WooProduct::SOURCE_TOYA_API)->whereHas('categories')->count(),
            'products_total'    => WooProduct::where('source', WooProduct::SOURCE_TOYA_API)->count(),
        ];
    }

    // ----------------------------------------------------------------
    // Table
    // ----------------------------------------------------------------

    public function table(Table $table): Table
    {
        return $table
            ->query(ToyaCategoryProposal::query()->with('proposedCategory'))
            ->defaultSort('product_count', 'desc')
            ->columns([
                TextColumn::make('toya_path')
                    ->label('Categorie Toya')
                    ->wrap()
                    ->searchable()
                    ->description(fn (ToyaCategoryProposal $r) => $r->product_count . ' produse'),

                TextColumn::make('proposedCategory.name')
                    ->label('Categorie WooCommerce propusă')
                    ->wrap()
                    ->default('—')
                    ->color(fn (ToyaCategoryProposal $r) => $r->proposed_woo_category_id ? null : 'gray'),

                TextColumn::make('confidence')
                    ->label('Încredere')
                    ->formatStateUsing(fn ($state) => $state ? round($state * 100) . '%' : '—')
                    ->badge()
                    ->color(fn ($state) => match (true) {
                        $state >= 0.8 => 'success',
                        $state >= 0.6 => 'warning',
                        $state !== null => 'danger',
                        default        => 'gray',
                    })
                    ->alignCenter(),

                TextColumn::make('reasoning')
                    ->label('Raționament AI')
                    ->wrap()
                    ->limit(120)
                    ->tooltip(fn (ToyaCategoryProposal $r) => $r->reasoning),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'pending'  => 'În așteptare',
                        'approved' => 'Aprobat',
                        'rejected' => 'Respins',
                        'no_match' => 'Fără potrivire',
                        default    => $state,
                    })
                    ->color(fn ($state) => match ($state) {
                        'approved' => 'success',
                        'rejected' => 'danger',
                        'no_match' => 'gray',
                        default    => 'warning',
                    })
                    ->alignCenter(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'pending'  => 'În așteptare',
                        'approved' => 'Aprobat',
                        'rejected' => 'Respins',
                        'no_match' => 'Fără potrivire',
                    ])
                    ->default('pending'),
            ])
            ->deferFilters(false)
            ->recordActions([
                TableAction::make('approve')
                    ->label('Aprobă')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (ToyaCategoryProposal $r) => in_array($r->status, ['pending', 'rejected']))
                    ->action(function (ToyaCategoryProposal $record) {
                        $record->update([
                            'status'      => 'approved',
                            'approved_by' => Auth::id(),
                            'approved_at' => now(),
                        ]);
                    }),

                TableAction::make('change_category')
                    ->label('Schimbă categoria')
                    ->icon('heroicon-o-pencil')
                    ->color('warning')
                    ->form([
                        Select::make('proposed_woo_category_id')
                            ->label('Categorie WooCommerce')
                            ->options(fn () => WooCategory::orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            ->required(),
                    ])
                    ->fillForm(fn (ToyaCategoryProposal $r) => [
                        'proposed_woo_category_id' => $r->proposed_woo_category_id,
                    ])
                    ->action(function (ToyaCategoryProposal $record, array $data) {
                        $record->update([
                            'proposed_woo_category_id' => $data['proposed_woo_category_id'],
                            'status'                   => 'approved',
                            'approved_by'              => Auth::id(),
                            'approved_at'              => now(),
                        ]);
                    }),

                TableAction::make('reject')
                    ->label('Respinge')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->visible(fn (ToyaCategoryProposal $r) => in_array($r->status, ['pending', 'approved']))
                    ->action(function (ToyaCategoryProposal $record) {
                        $record->update(['status' => 'rejected']);
                    }),

                TableAction::make('create_category')
                    ->label('Creează categorie nouă')
                    ->icon('heroicon-o-plus-circle')
                    ->color('info')
                    ->modalHeading('Creează categorie nouă în WooCommerce')
                    ->modalDescription('Categoria va fi creată instant în WooCommerce și salvată local cu woo_id-ul real.')
                    ->modalSubmitActionLabel('Creează și aprobă')
                    ->form(function (ToyaCategoryProposal $record): array {
                        // Pre-completăm numele cu ultimul segment din path-ul Toya
                        $segments  = explode(' / ', $record->toya_path);
                        $lastName  = trim(end($segments));

                        return [
                            TextInput::make('category_name')
                                ->label('Nume categorie')
                                ->default($lastName)
                                ->required()
                                ->maxLength(200),

                            Select::make('parent_category_id')
                                ->label('Categorie părinte')
                                ->placeholder('— Categorie rădăcină (fără părinte) —')
                                ->options(fn () => $this->buildHierarchicalCategoryOptions())
                                ->searchable()
                                ->nullable(),
                        ];
                    })
                    ->action(function (ToyaCategoryProposal $record, array $data) {
                        $name     = trim($data['category_name']);
                        $slug     = Str::slug($name);
                        $parentId = $data['parent_category_id'] ?: null;

                        // Determinăm woo_id-ul categoriei părinte (dacă există)
                        $parentWooId = null;
                        if ($parentId) {
                            $parentCat   = WooCategory::find($parentId);
                            $parentWooId = $parentCat?->woo_id;
                        }

                        // Creăm categoria în WooCommerce via REST API
                        $connection = IntegrationConnection::where('is_active', true)->firstOrFail();
                        $client     = new WooClient($connection);
                        $wooResult  = $client->createCategory($name, $slug, $parentWooId);

                        $wooId = $wooResult['id'] ?? null;

                        if (! $wooId) {
                            Notification::make()
                                ->title('Eroare WooCommerce')
                                ->body('Nu s-a putut crea categoria în WooCommerce.')
                                ->danger()
                                ->send();
                            return;
                        }

                        // Salvăm local în woo_categories
                        $localCat = WooCategory::create([
                            'connection_id' => $connection->id,
                            'woo_id'        => $wooId,
                            'name'          => $name,
                            'slug'          => $slug,
                            'parent_id'     => $parentId,
                            'parent_woo_id' => $parentWooId,
                            'count'         => 0,
                        ]);

                        // Actualizăm propunerea
                        $record->update([
                            'proposed_woo_category_id' => $localCat->id,
                            'status'                   => 'approved',
                            'approved_by'              => Auth::id(),
                            'approved_at'              => now(),
                            'reasoning'                => ($record->reasoning ? $record->reasoning . ' ' : '') . "[Categorie nouă creată: woo_id={$wooId}]",
                        ]);

                        Notification::make()
                            ->title('Categorie "' . $name . '" creata')
                            ->body('WooCommerce ID: ' . $wooId . ' - Propunerea a fost aprobata automat.')
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('bulk_approve')
                        ->label('Aprobă selectate')
                        ->icon('heroicon-o-check')
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(function (Collection $records) {
                            $records->each(fn ($r) => $r->update([
                                'status'      => 'approved',
                                'approved_by' => Auth::id(),
                                'approved_at' => now(),
                            ]));
                        }),

                    BulkAction::make('bulk_reject')
                        ->label('Respinge selectate')
                        ->icon('heroicon-o-x-mark')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->action(function (Collection $records) {
                            $records->each(fn ($r) => $r->update(['status' => 'rejected']));
                        }),

                    BulkAction::make('bulk_change_category')
                        ->label('Schimbă categoria la selectate')
                        ->icon('heroicon-o-tag')
                        ->color('warning')
                        ->form([
                            Select::make('proposed_woo_category_id')
                                ->label('Categorie WooCommerce')
                                ->options(fn () => WooCategory::orderBy('name')->pluck('name', 'id'))
                                ->searchable()
                                ->required(),
                        ])
                        ->action(function (Collection $records, array $data) {
                            $records->each(fn ($r) => $r->update([
                                'proposed_woo_category_id' => $data['proposed_woo_category_id'],
                                'status'                   => 'approved',
                                'approved_by'              => Auth::id(),
                                'approved_at'              => now(),
                            ]));
                        }),
                ]),
            ])
            ->striped()
            ->paginated([25, 50, 100]);
    }

    // ----------------------------------------------------------------
    // Helper: dropdown categorii cu ierarhie vizuală
    // ----------------------------------------------------------------

    private function buildHierarchicalCategoryOptions(): array
    {
        $cats   = WooCategory::orderBy('name')->get()->keyBy('id');
        $result = [];

        // Mai întâi categoriile rădăcină, apoi copiii cu indent
        $roots = $cats->whereNull('parent_id')->sortBy('name');

        foreach ($roots as $root) {
            $result[$root->id] = $root->name;
            $children = $cats->where('parent_id', $root->id)->sortBy('name');
            foreach ($children as $child) {
                $result[$child->id] = '— ' . $child->name;
                $grandchildren = $cats->where('parent_id', $child->id)->sortBy('name');
                foreach ($grandchildren as $grand) {
                    $result[$grand->id] = '—— ' . $grand->name;
                }
            }
        }

        return $result;
    }

    // ----------------------------------------------------------------
    // Dispatch 15 agenți AI
    // ----------------------------------------------------------------

    private function dispatchAgents(int $chunks = 15): array
    {
        // Extragem path-urile unice
        $products = WooProduct::where('source', WooProduct::SOURCE_TOYA_API)->get(['data']);
        $paths    = [];

        foreach ($products as $product) {
            $data = $product->data;
            if (is_string($data)) {
                $data = json_decode($data, true);
            }
            if (! is_array($data)) {
                continue;
            }
            $cat = $data['category_ro'] ?? '';
            if (empty($cat)) {
                continue;
            }
            $segs = array_filter(array_map('trim', explode('/', $cat)));
            $segs = array_values(array_filter(
                $segs,
                fn ($s) => $s !== '' && ! in_array(mb_strtolower($s), ['produkty', 'products', 'produse'], true)
            ));
            if (empty($segs)) {
                continue;
            }
            $path          = implode(' / ', $segs);
            $paths[$path]  = ($paths[$path] ?? 0) + 1;
        }

        arsort($paths);

        // Categorii WooCommerce cu ierarhie
        $cats    = DB::table('woo_categories')->select('id', 'name', 'parent_id')->get()->keyBy('id');
        $wooCats = [];
        foreach ($cats as $cat) {
            $hierarchy = [$cat->name];
            $parentId  = $cat->parent_id;
            while ($parentId && isset($cats[$parentId])) {
                $hierarchy[] = $cats[$parentId]->name;
                $parentId    = $cats[$parentId]->parent_id;
            }
            $wooCats[$cat->id] = implode(' > ', $hierarchy);
        }

        // Dispatch chunks
        $pathChunks = array_chunk($paths, (int) ceil(count($paths) / $chunks), true);

        foreach ($pathChunks as $index => $chunk) {
            dispatch(new ProposeToyaCategoryMappingJob($chunk, $wooCats, $index));
        }

        return ['paths' => count($paths), 'chunks' => count($pathChunks)];
    }

    // ----------------------------------------------------------------
    // Aplică mapările aprobate pe produse
    // ----------------------------------------------------------------

    private function applyApprovedMappings(): int
    {
        $approved = ToyaCategoryProposal::where('status', 'approved')
            ->whereNotNull('proposed_woo_category_id')
            ->get();

        $totalUpdated = 0;

        foreach ($approved as $proposal) {
            // Găsim toate produsele Toya cu acest path
            $products = WooProduct::where('source', WooProduct::SOURCE_TOYA_API)->get(['id', 'data']);

            foreach ($products as $product) {
                $data = $product->data;
                if (is_string($data)) {
                    $data = json_decode($data, true);
                }
                if (! is_array($data)) {
                    continue;
                }

                $cat  = $data['category_ro'] ?? '';
                $segs = array_filter(array_map('trim', explode('/', $cat)));
                $segs = array_values(array_filter(
                    $segs,
                    fn ($s) => $s !== '' && ! in_array(mb_strtolower($s), ['produkty', 'products', 'produse'], true)
                ));
                $path = implode(' / ', $segs);

                if ($path === $proposal->toya_path) {
                    DB::table('woo_product_category')->updateOrInsert(
                        [
                            'woo_product_id'  => $product->id,
                            'woo_category_id' => $proposal->proposed_woo_category_id,
                        ]
                    );
                    $totalUpdated++;
                }
            }
        }

        return $totalUpdated;
    }
}
