<?php

namespace App\Filament\App\Resources;
use App\Filament\App\Concerns\ChecksRolePermissions;
use App\Filament\App\Concerns\HasDynamicNavSort;

use App\Filament\App\Resources\PurchaseOrderResource\Pages;
use App\Models\ProductSupplier;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Models\WooProduct;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Placeholder;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Schemas\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Actions;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

class PurchaseOrderResource extends Resource
{
    use ChecksRolePermissions, HasDynamicNavSort;
    protected static ?string $model = PurchaseOrder::class;

    protected static string|\BackedEnum|null $navigationIcon   = 'heroicon-o-shopping-bag';
    protected static string|\UnitEnum|null $navigationGroup  = 'Achiziții';
    protected static ?string $navigationLabel  = 'Comenzi furnizori';
    protected static ?string $modelLabel       = 'Comandă furnizor';
    protected static ?string $pluralModelLabel = 'Comenzi furnizori';
    protected static ?int    $navigationSort   = 3;

    public static function getNavigationBadge(): ?string
    {
        if (! static::canCreate()) return null;

        $query = \App\Models\PurchaseRequestItem::query()
            ->where('status', \App\Models\PurchaseRequestItem::STATUS_PENDING)
            ->whereHas('purchaseRequest', fn ($q) => $q->whereIn('status', [
                \App\Models\PurchaseRequest::STATUS_SUBMITTED,
                \App\Models\PurchaseRequest::STATUS_PARTIALLY_ORDERED,
            ]))
            ->where(fn ($q) => $q
                ->whereDoesntHave('product')
                ->orWhereHas('product', fn ($p) => $p->where('is_discontinued', false))
            );

        $user = auth()->user();
        if ($user instanceof User && $user->role === User::ROLE_CONSULTANT_VANZARI) {
            $supplierIds = Supplier::where('buyer_id', $user->id)->pluck('id');
            $query->whereIn('supplier_id', $supplierIds);
        }

        $count = $query->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'danger';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            // ── Header: minimal on create, full on edit ──
            Section::make('Informații comandă')
                ->columns(3)
                ->columnSpanFull()
                ->schema(fn (string $operation): array => array_filter([
                    // PO number — only on edit (auto-generated on create)
                    $operation !== 'create' ? TextInput::make('number')
                        ->label('Număr PO')
                        ->disabled()
                        ->placeholder('Se generează automat') : null,

                    \Filament\Schemas\Components\Group::make([
                        Select::make('supplier_id')
                            ->label('Furnizor')
                            ->options(function (): array {
                                $query = Supplier::query()->where('is_active', true);

                                $user = auth()->user();
                                if ($user && ! $user->isAdmin()) {
                                    $query->whereHas('buyers', fn ($q) => $q->where('users.id', $user->id));
                                }

                                return $query->orderBy('name')->pluck('name', 'id')->all();
                            })
                            ->searchable()
                            ->required()
                            ->live()
                            ->disabledOn('edit')
                            ->hidden(fn () => filled(request()->query('supplier_id'))),

                        \Filament\Forms\Components\Placeholder::make('supplier_name')
                            ->label('Furnizor')
                            ->content(fn () => new \Illuminate\Support\HtmlString(
                                '<span style="font-size:1.25rem;font-weight:700;color:var(--gray-900)">'
                                . e(Supplier::find((int) request()->query('supplier_id'))?->name ?? '—')
                                . '</span>'
                            ))
                            ->visible(fn () => filled(request()->query('supplier_id'))),

                        \Filament\Forms\Components\Hidden::make('supplier_id')
                            ->visible(fn () => filled(request()->query('supplier_id'))),
                    ]),

                    // Status — only on edit
                    $operation !== 'create' ? Select::make('status')
                        ->label('Status')
                        ->options(PurchaseOrder::statusOptions())
                        ->disabled()
                        ->default(PurchaseOrder::STATUS_DRAFT) : null,

                    // On edit, notes stay in the header
                    $operation !== 'create' ? Textarea::make('notes_internal')
                        ->label('Notițe interne')
                        ->rows(2)
                        ->columnSpanFull() : null,

                    $operation !== 'create' ? Textarea::make('notes_supplier')
                        ->label('Notițe pentru furnizor')
                        ->rows(2)
                        ->columnSpanFull() : null,
                ])),

            // ── Items section ──
            Section::make('Produse comandate')
                ->columnSpanFull()
                ->schema(fn (string $operation): array => array_filter([
                    // Inject compact CSS for create mode — strips card styling from repeater
                    $operation === 'create' ? Placeholder::make('compact_css')
                        ->label('')->hiddenLabel()
                        ->content(new HtmlString(static::compactRepeaterCss()))
                        : null,

                    static::buildItemsRepeater($operation),

                    Placeholder::make('total_value')
                        ->label('Total comandă')
                        ->hidden($operation === 'create')
                        ->content(fn (Get $get): HtmlString => new HtmlString(
                            '<span class="text-xl font-bold">'.
                            number_format(
                                collect($get('items') ?? [])
                                    ->sum(fn ($item): float =>
                                        (float) ($item['quantity'] ?? 0) * (float) ($item['unit_price'] ?? 0)
                                    ),
                                2, ',', '.'
                            ).' RON</span>'
                        )),
                ])),

            // ── Notes: collapsible section on create ──
            Section::make('Notițe (opțional)')
                ->collapsed()
                ->columns(2)
                ->columnSpanFull()
                ->visibleOn('create')
                ->schema([
                    Textarea::make('notes_internal')
                        ->label('Notițe interne')
                        ->rows(2),

                    Textarea::make('notes_supplier')
                        ->label('Notițe pentru furnizor')
                        ->rows(2),
                ]),

            // ── WinMentor sync status ──
            Section::make('WinMentor')
                ->columnSpanFull()
                ->hiddenOn('create')
                ->visible(fn (?PurchaseOrder $record): bool => $record?->status === PurchaseOrder::STATUS_RECEIVED)
                ->columns(4)
                ->schema([
                    Placeholder::make('winmentor_sync_status')
                        ->label('Status sincronizare')
                        ->content(fn (?PurchaseOrder $record): HtmlString => new HtmlString(match($record?->winmentor_sync_status) {
                            PurchaseOrder::WINMENTOR_SYNCED  => '<span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">✓ Importat în WinMentor</span>',
                            PurchaseOrder::WINMENTOR_PENDING => '<span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">⏳ În așteptare</span>',
                            PurchaseOrder::WINMENTOR_FAILED  => '<span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium bg-red-100 text-red-800">✗ Eroare</span>',
                            default                          => '<span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-600">— Nesincronizat</span>',
                        })),

                    Placeholder::make('winmentor_synced_at')
                        ->label('Importat la')
                        ->content(fn (?PurchaseOrder $record): string => $record?->winmentor_synced_at
                            ? $record->winmentor_synced_at->format('d.m.Y H:i:s')
                            : '—'),

                    Placeholder::make('winmentor_order_nr')
                        ->label('Nr. document WinMentor')
                        ->content(fn (?PurchaseOrder $record): string => $record?->winmentor_order_nr ?? '—'),

                    Placeholder::make('winmentor_sync_error')
                        ->label('Eroare')
                        ->visible(fn (?PurchaseOrder $record): bool => filled($record?->winmentor_sync_error))
                        ->content(fn (?PurchaseOrder $record): HtmlString => new HtmlString(
                            '<span class="text-red-600 text-sm">' . e($record?->winmentor_sync_error ?? '') . '</span>'
                        ))
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('number')
                    ->label('Număr PO')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('supplier.name')
                    ->label('Furnizor')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn ($state): string => PurchaseOrder::statusColors()[$state] ?? 'gray')
                    ->formatStateUsing(fn ($state): string => PurchaseOrder::statusOptions()[$state] ?? $state),

                Tables\Columns\TextColumn::make('total_value')
                    ->label('Valoare')
                    ->formatStateUsing(fn ($state): string => number_format((float) $state, 2, ',', '.').' RON')
                    ->sortable(),

                Tables\Columns\TextColumn::make('buyer.name')
                    ->label('Resp. achiziții')
                    ->sortable(),

                Tables\Columns\TextColumn::make('sent_at')
                    ->label('Trimis la')
                    ->dateTime('d.m.Y')
                    ->placeholder('—')
                    ->sortable()
                    ->description(fn (PurchaseOrder $record): string => match($record->sent_via) {
                        'email'    => '✉ Email',
                        'whatsapp' => '💬 WhatsApp',
                        'manual'   => '📞 Manual',
                        default    => '',
                    }),

                Tables\Columns\TextColumn::make('reception_status')
                    ->label('Recepție')
                    ->badge()
                    ->getStateUsing(function (PurchaseOrder $record): ?string {
                        if (! in_array($record->status, [
                            PurchaseOrder::STATUS_RECEIVED,
                            PurchaseOrder::STATUS_SENT,
                        ], true)) {
                            return null;
                        }

                        if ($record->status === PurchaseOrder::STATUS_SENT) {
                            return 'așteptare';
                        }

                        // RECEIVED — verificăm dacă are lipsuri
                        $hasShortfall = $record->items->contains(
                            fn ($item): bool => $item->received_quantity !== null
                                && (float) $item->received_quantity < (float) $item->quantity
                        );

                        return $hasShortfall ? 'partiala' : 'completa';
                    })
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'completa'  => 'Completă',
                        'partiala'  => 'Parțială ⚠',
                        'așteptare' => 'Așteptare',
                        default     => '',
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'completa'  => 'success',
                        'partiala'  => 'warning',
                        'așteptare' => 'gray',
                        default     => 'gray',
                    }),

                Tables\Columns\TextColumn::make('winmentor_receptie_nr')
                    ->label('NIR WinMentor')
                    ->badge()
                    ->getStateUsing(function (PurchaseOrder $record): ?string {
                        if (! in_array($record->status, [PurchaseOrder::STATUS_RECEIVED, PurchaseOrder::STATUS_SENT], true)) {
                            return null;
                        }
                        if ($record->winmentor_receptie_nr) {
                            return 'confirmat';
                        }
                        if ($record->status === PurchaseOrder::STATUS_RECEIVED) {
                            return 'neconfirmat';
                        }
                        return null;
                    })
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'confirmat'   => '✓ Confirmat WM',
                        'neconfirmat' => '⚠ Neconfirmat',
                        default       => '',
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'confirmat'   => 'success',
                        'neconfirmat' => 'warning',
                        default       => 'gray',
                    })
                    ->tooltip(fn (PurchaseOrder $record): ?string => $record->winmentor_receptie_nr
                        ? "NIR: {$record->winmentor_receptie_nr} din {$record->winmentor_receptie_date} (scor {$record->winmentor_receptie_score}%)"
                        : null),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Data')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(PurchaseOrder::statusOptions()),

                Tables\Filters\SelectFilter::make('supplier_id')
                    ->label('Furnizor')
                    ->options(fn (): array => Supplier::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),

                Tables\Filters\TernaryFilter::make('partial_reception')
                    ->label('Recepție parțială')
                    ->queries(
                        true: fn (Builder $q) => $q
                            ->where('status', PurchaseOrder::STATUS_RECEIVED)
                            ->whereHas('items', fn ($i) => $i->whereColumn('received_quantity', '<', 'quantity')
                                ->whereNotNull('received_quantity')),
                        false: fn (Builder $q) => $q
                            ->where('status', PurchaseOrder::STATUS_RECEIVED)
                            ->whereDoesntHave('items', fn ($i) => $i->whereColumn('received_quantity', '<', 'quantity')
                                ->whereNotNull('received_quantity')),
                    ),
            ])
            ->deferFilters(false)
            ->recordActions([
                Actions\ViewAction::make(),
                Actions\EditAction::make()
                    ->visible(fn (PurchaseOrder $record): bool => $record->status === PurchaseOrder::STATUS_DRAFT),
                Actions\DeleteAction::make()
                    ->visible(fn (PurchaseOrder $record): bool => static::canDelete($record))
                    ->requiresConfirmation(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->schema([
            // ── 1. Identificare comandă ──────────────────────────────────────
            InfolistSection::make('Comandă')
                ->columns(4)
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('status')
                        ->label('Status')
                        ->badge()
                        ->color(fn ($state): string => PurchaseOrder::statusColors()[$state] ?? 'gray')
                        ->formatStateUsing(fn ($state): string => PurchaseOrder::statusOptions()[$state] ?? $state),
                    TextEntry::make('buyer.name')->label('Responsabil achiziții'),
                    TextEntry::make('total_value')
                        ->label('Valoare totală (fără TVA)')
                        ->formatStateUsing(fn ($state): string => number_format((float) $state, 2, ',', '.').' RON'),
                    TextEntry::make('currency')->label('Monedă'),
                ]),

            // ── 2. Flux status (timeline) ────────────────────────────────────
            InfolistSection::make('Flux comandă')
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('timeline')
                        ->hiddenLabel()
                        ->columnSpanFull()
                        ->html()
                        ->getStateUsing(function (PurchaseOrder $record): string {
                            $isRejected   = $record->status === PurchaseOrder::STATUS_REJECTED;
                            $isCancelled  = $record->status === PurchaseOrder::STATUS_CANCELLED;

                            $steps = [
                                [
                                    'label'  => 'Creat',
                                    'date'   => $record->created_at?->format('d.m.Y H:i'),
                                    'by'     => $record->buyer?->name,
                                    'done'   => true,
                                    'active' => $record->status === PurchaseOrder::STATUS_DRAFT,
                                ],
                                [
                                    'label'  => $isRejected ? 'Respins' : 'Aprobat',
                                    'date'   => $isRejected
                                        ? ($record->rejected_at?->format('d.m.Y H:i'))
                                        : ($record->approved_at?->format('d.m.Y H:i')),
                                    'by'     => $isRejected
                                        ? ($record->rejectedBy?->name ?? null)
                                        : ($record->approvedBy?->name ?? null),
                                    'error'  => $isRejected ? ($record->rejection_reason ?? null) : null,
                                    'done'   => in_array($record->status, [
                                        PurchaseOrder::STATUS_APPROVED,
                                        PurchaseOrder::STATUS_SENT,
                                        PurchaseOrder::STATUS_RECEIVED,
                                        PurchaseOrder::STATUS_REJECTED,
                                    ]),
                                    'active' => $record->status === PurchaseOrder::STATUS_PENDING_APPROVAL,
                                    'danger' => $isRejected,
                                ],
                                [
                                    'label'  => 'Trimis',
                                    'date'   => $record->sent_at?->format('d.m.Y H:i'),
                                    'by'     => $record->sentBy?->name,
                                    'note'   => match($record->sent_via) {
                                        'email'    => 'via Email',
                                        'whatsapp' => 'via WhatsApp',
                                        'manual'   => 'Manual (tel/fax)',
                                        default    => null,
                                    },
                                    'done'   => in_array($record->status, [
                                        PurchaseOrder::STATUS_SENT,
                                        PurchaseOrder::STATUS_RECEIVED,
                                    ]),
                                    'active' => $record->status === PurchaseOrder::STATUS_APPROVED,
                                    'skip'   => $isRejected || $isCancelled,
                                ],
                                [
                                    'label'  => 'Recepționat',
                                    'date'   => $record->received_at?->format('d.m.Y H:i'),
                                    'by'     => $record->receivedBy?->name,
                                    'note'   => $record->received_notes ?? null,
                                    'done'   => $record->status === PurchaseOrder::STATUS_RECEIVED,
                                    'active' => $record->status === PurchaseOrder::STATUS_SENT,
                                    'skip'   => $isRejected || $isCancelled,
                                ],
                                [
                                    'label'  => 'Contabilitate',
                                    'date'   => $record->winmentor_receptie_nr
                                        ? (function() use ($record): ?string {
                                            $ts = \Illuminate\Support\Facades\DB::table('winmentor_intrari_raw')
                                                ->where('nr_doc', $record->winmentor_receptie_nr)
                                                ->min('created_at');
                                            return $ts ? \Carbon\Carbon::parse($ts)->format('d.m.Y H:i') : null;
                                          })()
                                        : null,
                                    'by'     => $record->winmentor_receptie_nr
                                        ? 'Fact. ' . $record->winmentor_receptie_nr
                                        : null,
                                    'note'   => $record->winmentor_receptie_date
                                        ? 'Data factură: ' . \Carbon\Carbon::parse($record->winmentor_receptie_date)->format('d.m.Y')
                                        : null,
                                    'done'   => $record->winmentor_receptie_nr !== null,
                                    'active' => $record->status === PurchaseOrder::STATUS_RECEIVED && $record->winmentor_receptie_nr === null,
                                    'skip'   => $isRejected || $isCancelled,
                                ],
                            ];

                            $html = '<div style="display:flex;align-items:flex-start;gap:0;padding:8px 0 4px;">';

                            foreach ($steps as $i => $step) {
                                $isLast = $i === count($steps) - 1;
                                $skip   = $step['skip'] ?? false;

                                if ($skip && ! $step['done']) {
                                    if (! $isLast) {
                                        $html .= '<div style="flex:1;height:2px;background:#e5e7eb;margin-top:20px;opacity:0.4;"></div>';
                                    }
                                    continue;
                                }

                                $circleBg   = '#e5e7eb';
                                $circleText = '#9ca3af';
                                $textColor  = '#9ca3af';
                                $icon       = '○';

                                if ($step['done'] && ($step['danger'] ?? false)) {
                                    $circleBg   = '#fef2f2';
                                    $circleText = '#dc2626';
                                    $textColor  = '#374151';
                                    $icon       = '✕';
                                } elseif ($step['done']) {
                                    $circleBg   = '#dcfce7';
                                    $circleText = '#16a34a';
                                    $textColor  = '#374151';
                                    $icon       = '✓';
                                } elseif ($step['active']) {
                                    $circleBg   = '#dbeafe';
                                    $circleText = '#2563eb';
                                    $textColor  = '#1d4ed8';
                                    $icon       = '●';
                                }

                                $html .= '<div style="display:flex;flex-direction:column;align-items:center;min-width:120px;max-width:180px;flex:1;">';
                                $html .= '<div style="width:40px;height:40px;border-radius:50%;background:' . $circleBg . ';display:flex;align-items:center;justify-content:center;font-size:1rem;color:' . $circleText . ';font-weight:700;flex-shrink:0;">' . $icon . '</div>';
                                $html .= '<div style="text-align:center;margin-top:8px;">';
                                $html .= '<div style="font-size:0.8rem;font-weight:600;color:' . $textColor . ';">' . e($step['label']) . '</div>';

                                if ($step['date']) {
                                    $html .= '<div style="font-size:0.75rem;color:#6b7280;margin-top:2px;">' . e($step['date']) . '</div>';
                                }
                                if ($step['by']) {
                                    $html .= '<div style="font-size:0.72rem;color:#9ca3af;margin-top:1px;">' . e($step['by']) . '</div>';
                                }
                                if (! empty($step['note'])) {
                                    $html .= '<div style="font-size:0.7rem;color:#6b7280;margin-top:2px;max-width:160px;word-break:break-word;">' . e($step['note']) . '</div>';
                                }
                                if (! empty($step['error'])) {
                                    $html .= '<div style="font-size:0.7rem;color:#dc2626;margin-top:3px;max-width:160px;word-break:break-word;">' . e($step['error']) . '</div>';
                                }

                                $html .= '</div></div>';

                                if (! $isLast) {
                                    $lineColor = ($step['done'] && ! ($step['danger'] ?? false)) ? '#86efac' : '#e5e7eb';
                                    $html .= '<div style="flex:1;height:2px;background:' . $lineColor . ';margin-top:20px;"></div>';
                                }
                            }

                            $html .= '</div>';

                            if ($isCancelled) {
                                $html .= '<div style="margin-top:8px;padding:6px 12px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;font-size:0.8rem;color:#6b7280;">Comanda a fost anulată.</div>';
                            }

                            // Lead time calculat live din datele reale ERP
                            if ($record->sent_at && $record->received_at) {
                                $leadDays = (int) $record->sent_at->diffInDays($record->received_at);
                                $html .= '<div style="margin-top:8px;font-size:0.78rem;color:#9ca3af;text-align:center;">'
                                    . 'Lead time: <strong style="color:#6b7280;">' . $leadDays . ' ' . ($leadDays === 1 ? 'zi' : 'zile') . '</strong>'
                                    . ' de la trimitere până la recepție'
                                    . '</div>';
                            }

                            return $html;
                        }),
                ]),

            // ── 3. Factură furnizor ──────────────────────────────────────────
            InfolistSection::make('Factură furnizor')
                ->columns(4)
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('invoice_number')
                        ->label('Serie / Nr. factură')
                        ->placeholder('—')
                        ->getStateUsing(fn (PurchaseOrder $record): ?string =>
                            trim(($record->invoice_series ? $record->invoice_series . ' ' : '') . ($record->invoice_number ?? '')) ?: null
                        ),
                    TextEntry::make('invoice_date')->label('Data facturii')->date('d.m.Y')->placeholder('—'),
                    TextEntry::make('invoice_due_date')->label('Scadență')->date('d.m.Y')->placeholder('—'),
                    TextEntry::make('winmentor_receptie_score')
                        ->label('Scor potrivire WM')
                        ->formatStateUsing(fn ($state): string => $state !== null ? "{$state}%" : '—')
                        ->placeholder('—')
                        ->badge()
                        ->color(fn ($state): string => match(true) {
                            $state === null => 'gray',
                            $state >= 90    => 'success',
                            $state >= 70    => 'warning',
                            default         => 'danger',
                        }),
                ]),

            // ── 4. Note ─────────────────────────────────────────────────────
            InfolistSection::make('note_indicators')
                ->hiddenLabel()
                ->columnSpanFull()
                ->visible(fn (PurchaseOrder $record): bool =>
                    filled($record->notes_internal) || filled($record->notes_supplier) || filled($record->received_notes)
                )
                ->schema([
                    TextEntry::make('notes_html')
                        ->hiddenLabel()
                        ->columnSpanFull()
                        ->html()
                        ->getStateUsing(function (PurchaseOrder $record): string {
                            $notes = [
                                'Notițe interne'         => $record->notes_internal,
                                'Notițe pentru furnizor' => $record->notes_supplier,
                                'Observații recepție'    => $record->received_notes,
                            ];

                            $html = '<div style="display:flex;gap:8px;flex-wrap:wrap;">';
                            foreach ($notes as $label => $text) {
                                if (! filled($text)) continue;
                                $escaped = e($text);
                                $html .= '<details style="display:inline-block;">'
                                    . '<summary style="cursor:pointer;list-style:none;display:inline-flex;align-items:center;gap:4px;'
                                    . 'background:#fef2f2;border:1px solid #fecaca;border-radius:6px;padding:3px 10px;'
                                    . 'font-size:0.8rem;font-weight:600;color:#dc2626;user-select:none;">'
                                    . '▲ ' . e($label)
                                    . '</summary>'
                                    . '<div style="margin-top:6px;padding:10px 14px;background:#fff7f7;border:1px solid #fecaca;'
                                    . 'border-radius:6px;font-size:0.85rem;color:#374151;white-space:pre-wrap;max-width:600px;">'
                                    . $escaped
                                    . '</div>'
                                    . '</details>';
                            }
                            $html .= '</div>';
                            return $html;
                        }),
                ]),

            // ── 5. Produse comandate ─────────────────────────────────────────
            InfolistSection::make('Produse comandate')
                ->columnSpanFull()
                ->schema([
                    RepeatableEntry::make('items')
                        ->hiddenLabel()
                        ->columns(5)
                        ->schema([
                            TextEntry::make('product_name')
                                ->hiddenLabel()
                                ->columnSpan(2)
                                ->html()
                                ->getStateUsing(function (\App\Models\PurchaseOrderItem $record): string {
                                    $codes = array_filter([
                                        $record->sku        ? 'SKU: ' . $record->sku : null,
                                        $record->supplier_sku ? 'Cod furnizor: ' . $record->supplier_sku : null,
                                    ]);
                                    $sub = $codes
                                        ? '<div style="font-size:0.75rem;color:#9ca3af;margin-top:2px;">' . implode(' &nbsp;·&nbsp; ', $codes) . '</div>'
                                        : '';
                                    return '<div style="font-size:0.95rem;font-weight:600;color:#111827;">' . e($record->product_name) . '</div>' . $sub;
                                }),
                            TextEntry::make('quantity')
                                ->label('Comandat')
                                ->formatStateUsing(fn ($state) => $state !== null ? (floor((float)$state) == (float)$state ? number_format((float)$state, 0, '.', '') : number_format((float)$state, 2, '.', '')) . ' buc.' : '—'),
                            TextEntry::make('received_quantity')
                                ->label('Recepționat')
                                ->placeholder('—')
                                ->formatStateUsing(function ($state, \App\Models\PurchaseOrderItem $record): string {
                                    if ($state === null) return '—';
                                    $qty     = (float) $state;
                                    $ordered = (float) $record->quantity;
                                    $fmt     = floor($qty) == $qty ? number_format($qty, 0, '.', '') : number_format($qty, 2, '.', '');
                                    return $qty < $ordered ? $fmt . ' ⚠' : $fmt . ' buc.';
                                })
                                ->color(fn ($state, \App\Models\PurchaseOrderItem $record): string =>
                                    $state === null ? 'gray' :
                                    ((float) $state < (float) $record->quantity ? 'warning' : 'success')
                                )
                                ->badge(),
                            TextEntry::make('unit_price')
                                ->label('Preț (fără TVA)')
                                ->formatStateUsing(fn ($state): string => $state
                                    ? number_format((float) $state, 4, ',', '.').' RON'
                                    : '—'),
                            TextEntry::make('line_total')
                                ->label('Total linie')
                                ->formatStateUsing(fn ($state): string => number_format((float) $state, 2, ',', '.').' RON'),
                            TextEntry::make('notes')
                                ->label('Notițe')
                                ->placeholder('—')
                                ->columnSpan(2),
                        ]),
                ]),

            // ── 6. Recepție contabilă WinMentor ─────────────────────────────
            InfolistSection::make('Recepție contabilă WinMentor')
                ->columnSpanFull()
                ->visible(fn (PurchaseOrder $record): bool => in_array($record->status, [
                    PurchaseOrder::STATUS_RECEIVED,
                    PurchaseOrder::STATUS_SENT,
                ], true))
                ->schema([
                    \Filament\Schemas\Components\Grid::make(4)
                        ->schema([
                            TextEntry::make('winmentor_receptie_nr')
                                ->label('Nr. factură furnizor (WinMentor)')
                                ->placeholder('Neidentificat încă')
                                ->weight(\Filament\Support\Enums\FontWeight::Bold),
                            TextEntry::make('winmentor_receptie_date')
                                ->label('Data intrare WinMentor')
                                ->date('d.m.Y')
                                ->placeholder('—'),
                            TextEntry::make('winmentor_receptie_matched_at')
                                ->label('Asociat la')
                                ->dateTime('d.m.Y H:i')
                                ->placeholder('—'),
                        ]),

                    \Filament\Schemas\Components\Grid::make(2)
                        ->schema([
                            TextEntry::make('lead_time_days')
                                ->label('Lead time (zile de la trimitere la recepție)')
                                ->formatStateUsing(fn ($state): string => $state !== null ? "{$state} zile" : '—')
                                ->placeholder('—'),
                            TextEntry::make('receptie_contabila_lag_days')
                                ->label('Lag recepție contabilă (zile de la marfă la factură)')
                                ->formatStateUsing(fn ($state): string => $state !== null ? "{$state} zile" : '—')
                                ->placeholder('—'),
                        ]),

                    // ── Tabel comparativ PO vs WM ────────────────────────────
                    TextEntry::make('wm_comparison')
                        ->label('Comparativ linii PO vs WinMentor (prețuri fără TVA)')
                        ->columnSpanFull()
                        ->visible(fn (PurchaseOrder $record): bool => $record->winmentor_receptie_nr !== null)
                        ->html()
                        ->getStateUsing(function (PurchaseOrder $record): string {
                            $wmLines = \Illuminate\Support\Facades\DB::table('winmentor_intrari_raw')
                                ->where('nr_doc', $record->winmentor_receptie_nr)
                                ->whereNotNull('pret')
                                ->get(['sku', 'den_articol', 'cantitate', 'pret', 'uom'])
                                ->keyBy('sku');

                            $items     = $record->items()->get();
                            $rows      = '';
                            $totalErp  = 0.0;
                            $totalWm   = 0.0;

                            foreach ($items as $item) {
                                $wm      = $wmLines->get($item->sku ?? '');
                                $poPrice = (float) $item->unit_price;
                                $wmPrice = $wm ? (float) $wm->pret : null;
                                $wmQty   = $wm ? (float) $wm->cantitate : null;
                                $poQty   = (float) ($item->received_quantity ?? $item->quantity);

                                $lineErp = $poQty * $poPrice;
                                $lineWm  = ($wmQty !== null && $wmPrice !== null) ? $wmQty * $wmPrice : null;
                                $totalErp += $lineErp;
                                if ($lineWm !== null) $totalWm += $lineWm;

                                $priceDiff = ($wmPrice !== null && $poPrice > 0)
                                    ? round(($wmPrice - $poPrice) / $poPrice * 100, 1)
                                    : null;

                                $priceStyle = match(true) {
                                    $priceDiff === null   => 'color:#6b7280',
                                    abs($priceDiff) <= 2  => 'color:#16a34a;font-weight:600',
                                    $priceDiff > 2        => 'color:#dc2626;font-weight:600',
                                    default               => 'color:#ca8a04;font-weight:600',
                                };

                                $qtyMatch = $wmQty !== null && abs($wmQty - $poQty) < 0.01;
                                $qtyStyle = $qtyMatch ? 'color:#16a34a' : 'color:#dc2626;font-weight:600';

                                $rows .= '<tr style="border-bottom:1px solid #f3f4f6;">'
                                    . '<td style="padding:6px 10px;font-size:0.8rem;color:#6b7280;">' . e($item->sku ?? '—') . '</td>'
                                    . '<td style="padding:6px 10px;font-size:0.8rem;">' . e($item->product_name) . '</td>'
                                    . '<td style="padding:6px 10px;text-align:right;font-size:0.8rem;">' . number_format($poQty, 2, ',', '') . '</td>'
                                    . '<td style="padding:6px 10px;text-align:right;font-size:0.8rem;' . $qtyStyle . '">' . ($wmQty !== null ? number_format($wmQty, 2, ',', '') : '—') . '</td>'
                                    . '<td style="padding:6px 10px;text-align:right;font-size:0.8rem;">' . ($poPrice > 0 ? number_format($poPrice, 4, ',', '') . ' RON' : '—') . '</td>'
                                    . '<td style="padding:6px 10px;text-align:right;font-size:0.8rem;' . $priceStyle . '">' . ($wmPrice !== null ? number_format($wmPrice, 4, ',', '') . ' RON' : '—') . '</td>'
                                    . '<td style="padding:6px 10px;text-align:right;font-size:0.8rem;' . $priceStyle . '">' . ($priceDiff !== null ? ($priceDiff > 0 ? '+' : '') . $priceDiff . '%' : '—') . '</td>'
                                    . '</tr>';
                            }

                            $totalDiff = $totalErp > 0
                                ? round(($totalWm - $totalErp) / $totalErp * 100, 1)
                                : null;
                            $totalDiffStyle = match(true) {
                                $totalDiff === null  => 'color:#6b7280',
                                abs($totalDiff) <= 2 => 'color:#16a34a;font-weight:700',
                                $totalDiff > 2       => 'color:#dc2626;font-weight:700',
                                default              => 'color:#ca8a04;font-weight:700',
                            };
                            $totalRow = '<tr style="background:#f9fafb;border-top:2px solid #e5e7eb;">'
                                . '<td colspan="2" style="padding:8px 10px;font-size:0.8rem;font-weight:700;color:#374151;">TOTAL (fără TVA)</td>'
                                . '<td colspan="2" style="padding:8px 10px;"></td>'
                                . '<td style="padding:8px 10px;text-align:right;font-size:0.85rem;font-weight:700;">' . number_format($totalErp, 2, ',', '.') . ' RON</td>'
                                . '<td style="padding:8px 10px;text-align:right;font-size:0.85rem;font-weight:700;">' . ($totalWm > 0 ? number_format($totalWm, 2, ',', '.') . ' RON' : '—') . '</td>'
                                . '<td style="padding:8px 10px;text-align:right;font-size:0.85rem;' . $totalDiffStyle . '">' . ($totalDiff !== null ? ($totalDiff > 0 ? '+' : '') . $totalDiff . '%' : '—') . '</td>'
                                . '</tr>';

                            return '<div style="overflow-x:auto;margin-top:0.5rem;">'
                                . '<table style="width:100%;border-collapse:collapse;font-family:inherit;">'
                                . '<thead><tr style="background:#f9fafb;border-bottom:2px solid #e5e7eb;">'
                                . '<th style="padding:6px 10px;text-align:left;font-size:0.75rem;color:#6b7280;font-weight:600;">SKU</th>'
                                . '<th style="padding:6px 10px;text-align:left;font-size:0.75rem;color:#6b7280;font-weight:600;">Produs</th>'
                                . '<th style="padding:6px 10px;text-align:right;font-size:0.75rem;color:#6b7280;font-weight:600;">Cant. ERP</th>'
                                . '<th style="padding:6px 10px;text-align:right;font-size:0.75rem;color:#6b7280;font-weight:600;">Cant. WM</th>'
                                . '<th style="padding:6px 10px;text-align:right;font-size:0.75rem;color:#6b7280;font-weight:600;">Preț ERP (fără TVA)</th>'
                                . '<th style="padding:6px 10px;text-align:right;font-size:0.75rem;color:#6b7280;font-weight:600;">Preț WM (fără TVA)</th>'
                                . '<th style="padding:6px 10px;text-align:right;font-size:0.75rem;color:#6b7280;font-weight:600;">Δ%</th>'
                                . '</tr></thead>'
                                . '<tbody>' . $rows . $totalRow . '</tbody>'
                                . '</table></div>';
                        }),

                    // ── Mesaj când nu e asociat ──────────────────────────────
                    TextEntry::make('wm_no_match_hint')
                        ->label('')
                        ->columnSpanFull()
                        ->html()
                        ->visible(fn (PurchaseOrder $record): bool => $record->winmentor_receptie_nr === null && $record->status === PurchaseOrder::STATUS_RECEIVED)
                        ->getStateUsing(fn (): string =>
                            '<div style="padding:0.75rem 1rem;background:#fef3c7;border:1px solid #fcd34d;border-radius:0.5rem;color:#92400e;font-size:0.875rem;">'
                            . '⚠ Recepția contabilă nu a fost identificată automat în WinMentor. '
                            . 'Comanda de asociere rulează la fiecare 30 minute. '
                            . 'Dacă marfa a intrat în WinMentor, asocierea va apărea în curând.'
                            . '</div>'
                        ),
                ]),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['supplier', 'buyer', 'items', 'approvedBy', 'rejectedBy', 'sentBy', 'receivedBy']);

        $user = auth()->user();
        if ($user && $user->role === User::ROLE_CONSULTANT_VANZARI) {
            $query->whereHas('supplier', fn ($q) => $q->whereHas('buyers', fn ($q2) => $q2->where('users.id', $user->id)));
        }

        return $query;
    }

    public static function canCreate(): bool
    {
        $user = auth()->user();
        if (! $user instanceof User) return false;

        return $user->isSuperAdmin()
            || $user->isAdmin()
            || in_array($user->role, [
                User::ROLE_MANAGER_ACHIZITII,
                User::ROLE_MANAGER,
                User::ROLE_CONSULTANT_VANZARI,
                User::ROLE_DIRECTOR_FINANCIAR,
                User::ROLE_DIRECTOR_ECONOMIC,
                User::ROLE_DIRECTOR_VANZARI,
                User::ROLE_SUPORT_FINANCIAR,
            ], true);
    }

    public static function canDelete(Model $record): bool
    {
        $user = auth()->user();
        if (! $user instanceof User) return false;

        // Nimeni nu poate șterge comenzi în statusuri active
        if (! in_array($record->status, [
            PurchaseOrder::STATUS_DRAFT,
            PurchaseOrder::STATUS_CANCELLED,
            PurchaseOrder::STATUS_REJECTED,
        ], true)) {
            return false;
        }

        return $user->isSuperAdmin() || $user->isAdmin()
            || in_array($user->role, [
                User::ROLE_MANAGER_ACHIZITII,
                User::ROLE_MANAGER,
            ], true);
    }

    public static function canApprove(Model $record): bool
    {
        $user = auth()->user();
        if (! $user instanceof User) return false;

        return $user->isSuperAdmin()
            || in_array($user->role, [
                User::ROLE_MANAGER,
                User::ROLE_DIRECTOR_FINANCIAR,
                User::ROLE_DIRECTOR_VANZARI,
                User::ROLE_SUPORT_FINANCIAR,
            ], true);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListPurchaseOrders::route('/'),
            'create' => Pages\CreatePurchaseOrder::route('/create'),
            'view'   => Pages\ViewPurchaseOrder::route('/{record}'),
            'edit'   => Pages\EditPurchaseOrder::route('/{record}/edit'),
        ];
    }

    private static function buildItemsRepeater(string $operation): Repeater
    {
        $isCreate = $operation === 'create';

        $productSelectField = Select::make('woo_product_id')
            ->label('Caută produs')
            ->searchable()
            ->searchPrompt('Caută după nume sau SKU...')
            ->noSearchResultsMessage('Niciun produs găsit la acest furnizor.')
            ->getSearchResultsUsing(function (string $search, Get $get): array {
                $supplierId = (int) ($get('../../supplier_id') ?? 0);

                if (! $supplierId) {
                    return [];
                }

                return WooProduct::query()
                    ->join('product_suppliers as ps', function ($join) use ($supplierId) {
                        $join->on('ps.woo_product_id', '=', 'woo_products.id')
                             ->where('ps.supplier_id', $supplierId);
                    })
                    ->where(fn (Builder $q) => $q
                        ->where('woo_products.name', 'like', "%{$search}%")
                        ->orWhere('woo_products.sku', 'like', "%{$search}%")
                        ->orWhere('ps.supplier_sku', 'like', "%{$search}%")
                    )
                    ->limit(30)
                    ->get(['woo_products.id', 'woo_products.name', 'woo_products.sku', 'ps.supplier_sku'])
                    ->mapWithKeys(fn (WooProduct $p): array => [
                        $p->id => "[{$p->sku}] " . ($p->decoded_name ?? $p->name)
                            . ($p->supplier_sku ? " ({$p->supplier_sku})" : ''),
                    ])
                    ->all();
            })
            ->getOptionLabelUsing(function ($value): ?string {
                $p = WooProduct::find($value, ['id', 'name', 'sku']);
                if (! $p) return null;
                return "[{$p->sku}] " . ($p->decoded_name ?? $p->name);
            })
            ->live()
            ->afterStateUpdated(function ($state, Set $set, Get $get) use ($isCreate): void {
                if (! $state) return;

                $supplierId = (int) ($get('../../supplier_id') ?? 0);

                if ($supplierId) {
                    $ps = ProductSupplier::where('woo_product_id', $state)
                        ->where('supplier_id', $supplierId)
                        ->first();

                    if ($ps) {
                        $set('supplier_sku', $ps->supplier_sku);
                        if (! $isCreate && $ps->purchase_price) {
                            $set('unit_price', (float) $ps->purchase_price);
                        }
                        if ($isCreate) {
                            $set('info_purchase_uom', $ps->purchase_uom);
                            $set('info_conversion_factor', $ps->conversion_factor ? (float) $ps->conversion_factor : null);
                        }
                    }
                }

                $product = WooProduct::query()->find($state, ['id', 'name', 'sku', 'min_stock_qty', 'max_stock_qty']);
                if ($product) {
                    $set('product_name', $product->decoded_name ?? $product->name);
                    $set('sku', $product->sku);
                }

                if ($isCreate) {
                    // Fetch stock + velocity data pentru câmpurile info_*
                    $row = \Illuminate\Support\Facades\DB::table('woo_products as wp')
                        ->leftJoin('bi_product_velocity_current as bpv', 'bpv.reference_product_id', '=', 'wp.sku')
                        ->leftJoin(
                            \Illuminate\Support\Facades\DB::raw('(SELECT woo_product_id, COALESCE(SUM(quantity),0) as total_qty FROM product_stocks GROUP BY woo_product_id) stk'),
                            'stk.woo_product_id', '=', 'wp.id'
                        )
                        ->where('wp.id', $state)
                        ->select([
                            'wp.min_stock_qty',
                            'wp.max_stock_qty',
                            \Illuminate\Support\Facades\DB::raw('COALESCE(stk.total_qty, 0) as stock'),
                            \Illuminate\Support\Facades\DB::raw('COALESCE(bpv.avg_out_qty_7d, 0)  as avg7'),
                            \Illuminate\Support\Facades\DB::raw('COALESCE(bpv.avg_out_qty_30d, 0) as avg30'),
                            \Illuminate\Support\Facades\DB::raw('COALESCE(bpv.avg_out_qty_90d, 0) as avg90'),
                        ])
                        ->first();

                    if ($row) {
                        $avg7  = (float) $row->avg7;
                        $avg30 = (float) $row->avg30;
                        $avg90 = (float) $row->avg90;
                        $stock = (float) $row->stock;
                        $minStk = $row->min_stock_qty !== null ? (float) $row->min_stock_qty : null;
                        $maxStk = $row->max_stock_qty !== null ? (float) $row->max_stock_qty : null;

                        $base  = max($avg7, $avg30, $avg90);
                        $trend = ($avg30 > 0 && $avg7 > 0 && $avg7 < $avg30 * 0.85)
                            ? max(0.5, $avg7 / $avg30)
                            : 1.0;
                        $velDay      = $base * $trend;
                        $sales7d     = round($avg7 * 7, 1);
                        $sales30d    = round($avg30 * 30, 1);
                        $safetyStock = $velDay * 3;
                        $hint        = max(0, (int) ceil($velDay * 7 + $safetyStock - $stock));

                        // additional_store
                        if ($maxStk !== null && $maxStk > 0) {
                            $addStore   = max(0, (int) ceil($maxStk - $stock));
                            $calcMethod = 'max_stock';
                        } elseif ($hint > 0) {
                            $addStore   = $hint;
                            $calcMethod = 'velocity';
                        } else {
                            $addStore   = 0;
                            $calcMethod = null;
                        }

                        $daysToStockout = $avg7 > 0 ? round($stock / $avg7, 1) : null;

                        $set('info_stock',          $stock);
                        $set('info_sales_7d',        $sales7d);
                        $set('info_sales_30d',       $sales30d);
                        $set('info_days_stockout',   $daysToStockout);
                        $set('quantity_hint',        $addStore > 0 ? $addStore : null);
                        $set('recommendation_json', json_encode([
                            'from_requests'     => 0,
                            'reserved_qty'      => 0,
                            'general_qty'       => 0,
                            'current_stock'     => $stock,
                            'velocity_day'      => $velDay,
                            'sales_7d'          => $sales7d,
                            'sales_30d'         => $sales30d,
                            'min_stock_qty'     => $minStk,
                            'max_stock_qty'     => $maxStk,
                            'additional_store'  => $addStore,
                            'total_recommended' => $addStore,
                            'calc_method'       => $calcMethod,
                        ], JSON_UNESCAPED_UNICODE));
                    } else {
                        $set('info_stock',          null);
                        $set('info_sales_7d',        null);
                        $set('info_sales_30d',       null);
                        $set('info_days_stockout',   null);
                        $set('quantity_hint',        null);
                        $set('recommendation_json',  null);
                    }
                }
            })
            ->nullable();

        if ($isCreate) {
            // CREATE: ultra-compact table-like layout — 1 dense row per product
            // Columns: produs(5) | stoc+info(2) | recomandat(1) | cantitate(2) | context(2) = 12
            $schema = [
                // Product: thumbnail inline + name as compact display (only for pre-populated items)
                Placeholder::make('product_display')
                    ->label('')->hiddenLabel()
                    ->hidden(fn (Get $get): bool => blank($get('woo_product_id')))
                    ->content(function (Get $get): HtmlString {
                        $productId = (int) ($get('woo_product_id') ?? 0);
                        $name = $get('product_name') ?? '';
                        $sku = $get('sku') ?? '';
                        $supplierSku = $get('supplier_sku') ?? '';

                        $thumb = static::productThumbnail($productId ?: null);
                        $nameHtml = $name ? e(html_entity_decode($name, ENT_QUOTES, 'UTF-8')) : '<span style="color:#9ca3af">—</span>';
                        $skuParts = [];
                        if ($sku) $skuParts[] = e($sku);
                        if ($supplierSku) $skuParts[] = 'F: '.e($supplierSku);
                        $skuHtml = $skuParts ? '<div style="font-size:11px;color:#9ca3af;margin-top:1px">'.implode(' · ', $skuParts).'</div>' : '';

                        return new HtmlString(
                            '<div style="display:flex;align-items:center;gap:8px">'
                            .$thumb->toHtml()
                            .'<div style="min-width:0">'
                            .'<div style="font-size:13px;font-weight:500;color:#111827;line-height:1.3;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:320px" title="'.e(html_entity_decode($name, ENT_QUOTES, 'UTF-8')).'">'.$nameHtml.'</div>'
                            .$skuHtml
                            .'</div></div>'
                        );
                    })
                    ->columnSpan(5),

                // Product select — hidden when product already set (pre-populated), visible for new items
                $productSelectField
                    ->hidden(fn (Get $get): bool => filled($get('woo_product_id')))
                    ->columnSpan(5),

                // Info + context button combined: produs(5) | info+btn(3) | rec(1) | qty(3) = 12
                Placeholder::make('col_info')
                    ->label('Stoc / Vânzări')
                    ->content(function (Get $get): HtmlString {
                        $info = static::renderCompactInfo(
                            $get('info_stock'),
                            $get('info_sales_7d'),
                            $get('info_sales_30d'),
                            $get('info_days_stockout'),
                            $get('sources_json'),
                            $get('info_purchase_uom'),
                            $get('info_conversion_factor'),
                        );
                        $btn = static::renderSourcesInfo($get('sources_json'), $get('recommendation_json'), $get('product_name'), (int) $get('woo_product_id'));

                        return new HtmlString(
                            $info->toHtml()
                            .'<div style="margin-top:4px">'.$btn->toHtml().'</div>'
                        );
                    })
                    ->columnSpan(3),

                Placeholder::make('col_recommended')
                    ->label('Rec.')
                    ->content(fn (Get $get): HtmlString => static::renderRecommended(
                        $get('quantity_hint'),
                        $get('info_purchase_uom'),
                        $get('info_conversion_factor'),
                    ))
                    ->columnSpan(1),

                TextInput::make('quantity')
                    ->label('Cantitate')->numeric()->minValue(0)->nullable()
                    ->formatStateUsing(fn ($state) => $state !== null ? (float) $state : null)
                    ->placeholder(fn (Get $get): ?string => $get('quantity_hint') ? '→ '.((string)(int)$get('quantity_hint')) : null)
                    ->extraInputAttributes(['x-on:keydown.enter' => '$event.preventDefault()'])
                    ->helperText(function (Get $get): ?HtmlString {
                        $productId  = (int) ($get('woo_product_id') ?? 0);
                        $supplierId = (int) ($get('../../supplier_id') ?? 0);
                        if (! $productId || ! $supplierId) return null;
                        $ps = ProductSupplier::where('woo_product_id', $productId)
                            ->where('supplier_id', $supplierId)->first();
                        if (! $ps) return null;

                        $parts = [];
                        $qty = (float) ($get('quantity') ?? 0);

                        // UOM conversion
                        if ($ps->purchase_uom && $ps->conversion_factor && (float) $ps->conversion_factor > 0 && $qty > 0) {
                            $cf = (float) $ps->conversion_factor;
                            $unitsInUom = ceil($qty / $cf);
                            $parts[] = '<span style="color:#8B1A1A;font-weight:600">= ' . $unitsInUom . ' ' . e($ps->purchase_uom) . '</span>';
                        }

                        // Order multiple
                        if ($ps->order_multiple && (float) $ps->order_multiple > 0) {
                            $multipleStr = rtrim(rtrim(number_format((float) $ps->order_multiple, 3, '.', ''), '0'), '.');
                            $multipleLabel = 'Multiplu: ' . $multipleStr . ' buc';
                            if ($ps->purchase_uom && $ps->conversion_factor && (float) $ps->conversion_factor > 0) {
                                $multipleLabel .= ' (1 ' . e($ps->purchase_uom) . ')';
                            }
                            $parts[] = $multipleLabel;
                        }

                        return $parts ? new HtmlString(implode(' · ', $parts)) : null;
                    })
                    ->live(onBlur: true)
                    ->afterStateUpdated(function ($state, Set $set, Get $get): void {
                        if (! $state || (float) $state <= 0) return;
                        $productId  = (int) ($get('woo_product_id') ?? 0);
                        $supplierId = (int) ($get('../../supplier_id') ?? 0);
                        if (! $productId || ! $supplierId) return;
                        $ps = ProductSupplier::where('woo_product_id', $productId)
                            ->where('supplier_id', $supplierId)->first();
                        if (! $ps) return;
                        $qty = $ps->roundToOrderMultiple((float) $state);
                        if ($qty != (float) $state) {
                            $set('quantity', $qty);
                        }
                        $priceBreak = $ps->getBestPriceForQty($qty);
                        if ($priceBreak) {
                            $set('unit_price', (float) $priceBreak->unit_price);
                        } elseif ($ps->purchase_price) {
                            $set('unit_price', (float) $ps->purchase_price);
                        }
                    })
                    ->columnSpan(3),

                // Hidden — data storage (all still present, unchanged)
                Hidden::make('product_name'),
                Hidden::make('unit_price'),
                Hidden::make('sku'),
                Hidden::make('supplier_sku'),
                Hidden::make('notes'),
                Hidden::make('sources_json'),
                Hidden::make('recommendation_json'),
                Hidden::make('quantity_hint'),
                Hidden::make('info_stock'),
                Hidden::make('info_sales_7d'),
                Hidden::make('info_sales_30d'),
                Hidden::make('info_days_stockout'),
                Hidden::make('info_purchase_uom'),
                Hidden::make('info_conversion_factor'),
            ];
        } else {
            // EDIT: toate câmpurile vizibile, cu prețuri
            $schema = [
                Placeholder::make('thumbnail')
                    ->label('')->hiddenLabel()
                    ->content(fn (Get $get): HtmlString => static::productThumbnail($get('woo_product_id')))
                    ->extraAttributes(['class' => 'w-12']),
                $productSelectField,
                TextInput::make('product_name')->label('Denumire produs')->required(),
                TextInput::make('sku')->label('SKU intern')->nullable(),
                TextInput::make('supplier_sku')->label('SKU furnizor')->nullable(),
                TextInput::make('quantity')
                    ->label('Cantitate')->numeric()->minValue(0.001)->required()
                    ->default(1)
                    ->formatStateUsing(fn ($state) => $state !== null ? (float) $state : null)
                    ->extraInputAttributes(['x-on:keydown.enter' => '$event.preventDefault()'])
                    ->helperText(function (Get $get): ?string {
                        $productId  = (int) ($get('woo_product_id') ?? 0);
                        $supplierId = (int) ($get('../../supplier_id') ?? 0);
                        if (! $productId || ! $supplierId) return null;
                        $ps = ProductSupplier::where('woo_product_id', $productId)
                            ->where('supplier_id', $supplierId)->first();
                        if ($ps && $ps->order_multiple && (float) $ps->order_multiple > 0) {
                            return 'Multiplu: ' . rtrim(rtrim(number_format((float) $ps->order_multiple, 3, '.', ''), '0'), '.') . ' buc';
                        }
                        return null;
                    })
                    ->live(onBlur: true)
                    ->afterStateUpdated(function ($state, Set $set, Get $get): void {
                        if ($state && (float) $state > 0) {
                            $productId  = (int) ($get('woo_product_id') ?? 0);
                            $supplierId = (int) ($get('../../supplier_id') ?? 0);
                            if ($productId && $supplierId) {
                                $ps = ProductSupplier::where('woo_product_id', $productId)
                                    ->where('supplier_id', $supplierId)->first();
                                if ($ps) {
                                    $qty = $ps->roundToOrderMultiple((float) $state);
                                    if ($qty != (float) $state) {
                                        $set('quantity', $qty);
                                        $state = $qty;
                                    }
                                    $priceBreak = $ps->getBestPriceForQty($qty);
                                    if ($priceBreak) {
                                        $set('unit_price', (float) $priceBreak->unit_price);
                                    } elseif ($ps->purchase_price) {
                                        $set('unit_price', (float) $ps->purchase_price);
                                    }
                                }
                            }
                        }
                        $set('line_total_preview', static::computeLineTotal($get('unit_price'), $state));
                    }),
                TextInput::make('unit_price')
                    ->label('Preț unitar')->numeric()->minValue(0)->nullable()->suffix('RON')
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn ($state, Set $set, Get $get) =>
                        $set('line_total_preview', static::computeLineTotal($state, $get('quantity')))
                    ),
                Placeholder::make('line_total_preview')
                    ->label('Total linie')
                    ->content(fn (Get $get): HtmlString => new HtmlString(
                        '<span class="font-semibold text-sm">'.
                        number_format((float)($get('quantity') ?? 0) * (float)($get('unit_price') ?? 0), 2, ',', '.').
                        ' RON</span>'
                    )),
                Placeholder::make('sources_info')
                    ->label('Surse')->hiddenLabel()
                    ->content(fn (Get $get): HtmlString => static::renderSourcesInfo($get('sources_json'), $get('recommendation_json'), $get('product_name'), (int) $get('woo_product_id'))),
                TextInput::make('notes')->label('Notițe')->nullable(),
                Hidden::make('sources_json'),
                Hidden::make('recommendation_json'),
                Hidden::make('quantity_hint'),
            ];
        }

        return Repeater::make('items')
            ->label('')
            ->relationship('items')
            ->schema($schema)
            ->columns($isCreate ? 12 : 10)
            ->defaultItems($isCreate ? 0 : 1)
            ->addActionLabel('Adaugă produs')
            ->mutateRelationshipDataBeforeCreateUsing(function (array $data): ?array {
                // Nu salvăm items fără cantitate (lăsate goale în formularul de creare)
                if (! isset($data['quantity']) || (float) $data['quantity'] <= 0) {
                    return null;
                }
                return $data;
            });
    }

    private static function productThumbnail(?int $productId): HtmlString
    {
        static $cache = [];

        $placeholder = '<span style="display:inline-flex;align-items:center;justify-content:center;width:56px;height:56px;border-radius:6px;background:#f3f4f6;color:#9ca3af;font-size:9px;flex-shrink:0">—</span>';

        if (! $productId) {
            return new HtmlString($placeholder);
        }

        if (! array_key_exists($productId, $cache)) {
            $cache[$productId] = WooProduct::query()->whereKey($productId)->value('main_image_url');
        }

        $url = filled($cache[$productId]) ? e((string) $cache[$productId]) : null;

        if (! $url) {
            return new HtmlString($placeholder);
        }

        return new HtmlString(
            '<img src="'.$url.'" alt="" style="width:56px;height:56px;border-radius:6px;object-fit:cover;flex-shrink:0;border:1px solid #e5e7eb" loading="lazy" />'
        );
    }

    private static function renderDaysToStockout(mixed $days): string
    {
        if ($days === null || $days === '') {
            return '<span class="text-gray-300 text-sm">—</span>';
        }

        $d = (float) $days;

        if ($d <= 0) {
            return '<span class="text-xs font-semibold text-red-600 bg-red-50 px-1.5 py-0.5 rounded">Epuizat</span>';
        }

        if ($d < 7) {
            return '<span class="text-xs font-semibold text-red-600">'.round($d, 1).' zile</span>';
        }

        if ($d < 14) {
            return '<span class="text-xs font-semibold text-orange-500">'.round($d, 1).' zile</span>';
        }

        return '<span class="text-xs text-gray-500">'.round($d, 1).' zile</span>';
    }

    /**
     * Compact stacked info: stock, sales 7d/30d, days to stockout, urgency.
     */
    private static function renderCompactInfo(mixed $stock, mixed $sales7d, mixed $sales30d, mixed $daysStockout, ?string $sourcesJson, ?string $purchaseUom = null, mixed $conversionFactor = null): HtmlString
    {
        $hasUrgent = false;
        if ($sourcesJson) {
            $sources = json_decode($sourcesJson, true);
            if (is_array($sources)) {
                $hasUrgent = collect($sources)->contains(fn ($s) => ! empty($s['is_urgent']));
            }
        }

        $lbl = 'style="font-size:11px;color:#9ca3af"';
        $cells = [];

        // Urgent badge
        if ($hasUrgent) {
            $cells[] = '<span style="display:inline-block;border-radius:3px;padding:0 5px;font-size:10px;font-weight:700;background:#fee2e2;color:#b91c1c;letter-spacing:0.05em;line-height:18px">URGENT</span>';
        }

        // Stock — bold, color-coded
        if ($stock !== null && $stock !== '') {
            $stockVal = (float) $stock;
            $stockColor = '#374151';
            if ($stockVal <= 0) $stockColor = '#dc2626';
            elseif ($daysStockout !== null && (float) $daysStockout < 7) $stockColor = '#dc2626';
            elseif ($daysStockout !== null && (float) $daysStockout < 14) $stockColor = '#ea580c';

            $cells[] = '<span '.$lbl.'>Stoc:</span> <b style="color:'.$stockColor.'">'.number_format($stockVal, 0, '.', '').' buc</b>';
        }

        // Sales 7d
        if ($sales7d !== null && $sales7d !== '' && (float) $sales7d > 0) {
            $cells[] = '<span '.$lbl.'>Vânz. 7 zile:</span> <span style="font-weight:500;color:#374151">'.number_format((float) $sales7d, 0, '.', '').' buc</span>';
        }

        // Sales 30d
        if ($sales30d !== null && $sales30d !== '' && (float) $sales30d > 0) {
            $cells[] = '<span '.$lbl.'>Vânz. 30 zile:</span> <span style="font-weight:500;color:#374151">'.number_format((float) $sales30d, 0, '.', '').' buc</span>';
        }

        // Days to stockout
        if ($daysStockout !== null && $daysStockout !== '') {
            $d = (float) $daysStockout;
            if ($d <= 0) {
                $cells[] = '<span '.$lbl.'>Epuizare stoc:</span> <b style="color:#dc2626">EPUIZAT</b>';
            } else {
                $color = $d < 7 ? '#dc2626' : ($d < 14 ? '#ea580c' : '#374151');
                $cells[] = '<span '.$lbl.'>Epuizare stoc:</span> <span style="font-weight:600;color:'.$color.'">'.round($d, 0).' zile</span>';
            }
        }

        // UOM info
        if ($purchaseUom && $conversionFactor && (float) $conversionFactor > 0) {
            $cfVal = (int) $conversionFactor;
            $cells[] = '<span '.$lbl.'>Cmd:</span> <span style="font-weight:500;color:#8B1A1A">'.e($purchaseUom).' ('.$cfVal.' buc/'.e($purchaseUom).')</span>';
        }

        if (empty($cells)) {
            return new HtmlString('<span style="font-size:11px;color:#d1d5db">—</span>');
        }

        return new HtmlString(
            '<div style="display:flex;flex-direction:column;gap:1px;line-height:1.6;font-size:12px">'
            . implode('', array_map(fn ($c) => '<div>'.$c.'</div>', $cells))
            . '</div>'
        );
    }

    /**
     * Clickable recommended quantity that fills the quantity input.
     */
    private static function renderRecommended(mixed $hint, ?string $purchaseUom = null, mixed $conversionFactor = null): HtmlString
    {
        if (! $hint || (int) $hint <= 0) {
            return new HtmlString('<span style="font-size:12px;color:#d1d5db">—</span>');
        }

        $qty = (int) $hint;
        $cf = $conversionFactor ? (float) $conversionFactor : 0;
        $hasUom = $purchaseUom && $cf > 0;

        // UOM display: "3 paleți (720 buc)"
        $uomHtml = '';
        if ($hasUom) {
            $unitsInUom = ceil($qty / $cf);
            $uomLabel = e($purchaseUom);
            $uomHtml = '<div style="font-size:11px;color:#6b7280;margin-top:1px">'
                . $unitsInUom . ' ' . $uomLabel
                . '</div>';
        }

        // Inline onclick — find input by type=number in parent LI
        $onclick = "var p=this;while(p&amp;&amp;p.tagName!=='LI'){p=p.parentElement}"
            . "if(!p)return;"
            . "var inp=p.querySelector('input[type=number]');"
            . "if(!inp)return;"
            . "var s=Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype,'value').set;"
            . "s.call(inp,'{$qty}');"
            . "inp.dispatchEvent(new Event('input',{bubbles:true}));"
            . "inp.dispatchEvent(new Event('change',{bubbles:true}));"
            . "inp.focus();"
            . "setTimeout(function(){inp.dispatchEvent(new Event('blur',{bubbles:true}))},100);";

        return new HtmlString(
            '<div style="cursor:pointer;padding:3px 8px;border-radius:6px;background:#fdf2f2;border:1px solid #e8c4c4;transition:background .15s;display:inline-block"'
            .' onclick="'.$onclick.'"'
            .' onmouseover="this.style.background=\'#f5e0e0\'"'
            .' onmouseout="this.style.background=\'#fdf2f2\'"'
            .' title="Click pentru a aplica cantitatea recomandată">'
            .'<div style="display:flex;align-items:center;gap:4px">'
            .'<span style="font-size:15px;font-weight:700;color:#8B1A1A;font-variant-numeric:tabular-nums">'.$qty.'</span>'
            .'<span style="font-size:10px;color:#6b7280">buc</span>'
            .'<svg style="width:12px;height:12px;color:#8B1A1A;flex-shrink:0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">'
            .'<path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5m0 0l-5 5m5-5H6"/>'
            .'</svg>'
            .'</div>'
            .$uomHtml
            .'</div>'
        );
    }

    /**
     * CSS injected into the create form to make repeater items look like compact table rows.
     */
    private static function compactRepeaterCss(): string
    {
        return <<<'CSS'
<style>
/* ── PO Create: compact table-like repeater ── */

/* Strip card borders & shadows from repeater items */
[wire\:sortable\.item] {
    border: none !important;
    box-shadow: none !important;
    border-radius: 0 !important;
    background: transparent !important;
    border-bottom: 1px solid #f3f4f6 !important;
}
[wire\:sortable\.item]:hover {
    background: #fafbfc !important;
}

/* Remove gap between items, add outer border */
[wire\:sortable] {
    gap: 0 !important;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    overflow: hidden;
}

/* Reduce padding inside each item row */
[wire\:sortable\.item] > div {
    padding: 6px 8px !important;
}

/* Compact grid gap */
[wire\:sortable\.item] .grid {
    gap: 6px !important;
    align-items: center !important;
}

/* Smaller labels */
[wire\:sortable\.item] label {
    font-size: 11px !important;
    margin-bottom: 1px !important;
}

/* Compact inputs */
[wire\:sortable\.item] input {
    padding: 4px 8px !important;
    font-size: 13px !important;
    height: 32px !important;
}

/* Move delete button inline */
[wire\:sortable\.item] [data-action] {
    position: absolute;
    top: 4px;
    right: 4px;
}
</style>
CSS;
    }

    private static function computeLineTotal($price, $qty): string
    {
        return number_format((float) ($price ?? 0) * (float) ($qty ?? 0), 2, ',', '.');
    }

    public static function renderSourcesInfo(?string $json, ?string $recJson = null, ?string $productName = null, int $productId = 0): HtmlString
    {
        $sources        = $json ? json_decode($json, true) : null;
        $requestSources = is_array($sources) ? array_filter($sources, fn ($s) => ! empty($s['request_item_id'])) : [];
        $count          = count($requestSources);
        $rec            = ($recJson && $recJson !== 'null') ? json_decode($recJson, true) : null;

        // If neither requests nor recommendation data, show placeholder
        if ($count === 0 && (! is_array($rec))) {
            if (blank($json)) {
                return new HtmlString('<span style="font-size:12px;color:#9ca3af">—</span>');
            }
            return new HtmlString('<span style="font-size:12px;color:#9ca3af;font-style:italic">velocitate</span>');
        }

        // Button label
        if ($count > 0) {
            $label = $count === 1 ? '1 necesar' : "{$count} necesare";
        } else {
            $label = 'velocitate';
        }

        // ---- helper: row cu label / valoare ----
        $row = fn (string $label, string $value, string $valueStyle = 'color:#111827;font-weight:500'): string =>
            '<div style="display:flex;align-items:center;justify-content:space-between;gap:16px;padding:8px 12px">'
            .'<span style="font-size:14px;color:#6b7280">'.$label.'</span>'
            .'<span style="font-size:14px;'.$valueStyle.'">'.$value.'</span>'
            .'</div>';

        // ---- Section 1: request sources ----
        $rows = '';
        foreach ($requestSources as $src) {
            $qty         = number_format((float) ($src['quantity'] ?? 0), 0, '.', '');
            $number      = e($src['request_number'] ?? '—');
            $consultant  = e($src['consultant'] ?? '—');
            $location    = e($src['location'] ?? '');
            $requestedAt = e($src['requested_at'] ?? '');
            $neededBy    = isset($src['needed_by']) && $src['needed_by']
                ? date('d.m.Y', strtotime((string) $src['needed_by']))
                : null;
            $clientRef   = e($src['client_reference'] ?? '');
            $requestId   = (int) ($src['request_id'] ?? 0);
            $url         = $requestId ? '/purchase-requests/'.$requestId : null;
            $urgentBadge = ! empty($src['is_urgent'])
                ? '<span style="margin-left:8px;display:inline-flex;align-items:center;border-radius:6px;padding:2px 8px;font-size:12px;font-weight:700;background:#fee2e2;color:#b91c1c;outline:1px solid #fecaca">URGENT</span>'
                : '';

            $link = $url
                ? '<a href="'.e($url).'" target="_blank" rel="noopener"
                        style="font-size:16px;font-weight:700;color:#8B1A1A;text-decoration:none;display:inline-flex;align-items:center;gap:6px">
                        '.$number.'
                        <svg style="width:14px;height:14px;flex-shrink:0;opacity:.7;display:inline;vertical-align:middle" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                          <path stroke-linecap="round" stroke-linejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                        </svg>
                      </a>'
                : '<span style="font-size:16px;font-weight:700;color:#1f2937">'.$number.'</span>';

            $rows .= '<div style="border-radius:8px;border:1px solid #e5e7eb;overflow:hidden">';
            // header card
            $rows .= '<div style="display:flex;align-items:center;gap:8px;background:#f9fafb;padding:8px 12px;border-bottom:1px solid #e5e7eb">'.$link.$urgentBadge.'</div>';
            // rows alternante
            $rows .= '<div>';
            $rows .= $row('Creat de', $consultant);
            if ($location)    $rows .= $row('Locație', $location);
            if ($requestedAt) $rows .= $row('Data cererii', $requestedAt);
            if ($neededBy)    $rows .= $row('Necesar până la', '<span style="font-weight:600;color:#b45309">'.$neededBy.'</span>', '');
            if ($clientRef)   $rows .= $row('Ref. client', '<span style="font-weight:600;color:#c2410c">'.$clientRef.'</span> <span style="margin-left:4px;font-size:12px;background:#fff7ed;color:#ea580c;border-radius:4px;padding:2px 6px;font-weight:500">rezervat</span>', '');
            $rows .= $row('Cantitate solicitată', '<span style="font-size:16px;font-weight:700;color:#111827">'.$qty.' buc.</span>', '');
            $rows .= '</div>';
            $rows .= '</div>';
        }

        // ---- Section 2: recommendation breakdown ----
        $recSection = '';
        if (is_array($rec)) {
            $fromReq    = (float) ($rec['from_requests'] ?? 0);
            $reserved   = (float) ($rec['reserved_qty'] ?? 0);
            $general    = (float) ($rec['general_qty'] ?? 0);
            $stock      = (float) ($rec['current_stock'] ?? 0);
            $velDay     = (float) ($rec['velocity_day'] ?? 0);
            $sales7d    = (float) ($rec['sales_7d'] ?? 0);
            $sales30d   = (float) ($rec['sales_30d'] ?? 0);
            $minStk     = isset($rec['min_stock_qty']) && $rec['min_stock_qty'] !== null ? (float) $rec['min_stock_qty'] : null;
            $maxStk     = isset($rec['max_stock_qty']) && $rec['max_stock_qty'] !== null ? (float) $rec['max_stock_qty'] : null;
            $addStore   = (float) ($rec['additional_store'] ?? 0);
            $totalRec   = (float) ($rec['total_recommended'] ?? 0);
            $method     = $rec['calc_method'] ?? null;

            $recSection .= '<div style="border-radius:8px;border:1px solid #e8c4c4;overflow:hidden">';
            $recSection .= '<div style="background:#fdf2f2;padding:8px 12px;border-bottom:1px solid #e8c4c4">';
            $recSection .= '<span style="font-size:14px;font-weight:700;color:#8B1A1A">Recomandare cantitate</span>';
            $recSection .= '</div>';
            $recSection .= '<div>';

            if ($fromReq > 0) {
                $recSection .= $row('Din necesare', number_format($fromReq, 0, '.', '').' buc.');
                if ($reserved > 0) {
                    $recSection .= $row('— din care rezervate client', '<span style="color:#c2410c;font-weight:600">'.number_format($reserved, 0, '.', '').' buc.</span>', '');
                }
                if ($general > 0) {
                    $recSection .= $row('— din care pt. stoc general', number_format($general, 0, '.', '').' buc.');
                }
            }

            $recSection .= $row('Stoc curent', number_format($stock, 0, '.', '').' buc.');

            if ($sales7d > 0 || $sales30d > 0) {
                $recSection .= $row('Mișcări ultimele 7 zile', number_format($sales7d, 1, '.', '').' buc.');
                $recSection .= $row('Mișcări ultimele 30 zile', number_format($sales30d, 1, '.', '').' buc.');
                $recSection .= $row('Velocitate ajustată', number_format($velDay, 2, '.', '').' buc./zi');
            }

            if ($minStk !== null) {
                $recSection .= $row('Stoc minim (reorder point)', number_format($minStk, 0, '.', '').' buc.');
            }
            if ($maxStk !== null) {
                $recSection .= $row('Stoc maxim (target)', number_format($maxStk, 0, '.', '').' buc.');
            }

            if ($addStore > 0) {
                $methodLabel = match ($method) {
                    'max_stock' => 'Suplimentar pt. stoc maxim',
                    'velocity'  => 'Suplimentar pt. stoc magazin',
                    default     => 'Suplimentar magazin',
                };
                $recSection .= $row($methodLabel, '<span style="color:#8B1A1A;font-weight:700">+'.number_format($addStore, 0, '.', '').' buc.</span>', '');
            }

            $recSection .= '</div>';
            // Total footer
            $recSection .= '<div style="display:flex;align-items:center;justify-content:space-between;background:#8B1A1A;padding:12px">';
            $recSection .= '<span style="font-size:14px;font-weight:700;color:#fff">Total recomandat</span>';
            $recSection .= '<span style="font-size:18px;font-weight:900;color:#fff">'.number_format($totalRec, 0, '.', '').' buc.</span>';
            $recSection .= '</div>';
            $recSection .= '</div>';
        }

        $uid = 'src_'.substr(md5(($json ?? '').'|'.($recJson ?? '')), 0, 8);

        // Product info for modal header
        $productHeaderHtml = '';
        if ($productName || $productId) {
            $displayName = $productName ?: '—';
            $priceHtml   = '';
            if ($productId) {
                $product = WooProduct::query()->find($productId, ['id', 'name', 'regular_price', 'sale_price', 'price']);
                if ($product) {
                    $displayName = $product->decoded_name ?: $displayName;
                    $priceVal    = filled($product->sale_price) ? $product->sale_price : ($product->regular_price ?: $product->price);
                    if ($priceVal) {
                        $formatted = number_format((float) $priceVal, 2, ',', '.').' RON';
                        $isSale    = filled($product->sale_price) && (float) $product->sale_price < (float) $product->regular_price;
                        if ($isSale) {
                            $regular   = number_format((float) $product->regular_price, 2, ',', '.').' RON';
                            $priceHtml = '<span style="font-size:14px;font-weight:700;color:#15803d">'.$formatted.'</span>'
                                        .' <span style="font-size:12px;color:#9ca3af;text-decoration:line-through">'.$regular.'</span>';
                        } else {
                            $priceHtml = '<span style="font-size:14px;font-weight:700;color:#374151">'.$formatted.'</span>';
                        }
                    }
                }
            }
            $productUrl  = $productId ? '/produse/'.$productId : null;
            $nameHtml    = $productUrl
                ? '<a href="'.e($productUrl).'" target="_blank" rel="noopener"
                       style="font-size:14px;font-weight:600;color:#8B1A1A;text-decoration:none;display:inline-flex;align-items:center;gap:6px;line-height:1.4">
                       '.e($displayName).'
                       <svg style="width:14px;height:14px;flex-shrink:0;opacity:.7;display:inline;vertical-align:middle" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                         <path stroke-linecap="round" stroke-linejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                       </svg>
                     </a>'
                : '<span style="font-size:14px;font-weight:600;color:#111827">'.e($displayName).'</span>';

            $productHeaderHtml = '<div style="padding:12px 24px;background:#f9fafb;border-bottom:1px solid #e5e7eb">'
                .'<div>'.$nameHtml.'</div>'
                .($priceHtml ? '<p style="display:flex;align-items:center;gap:8px;margin-top:4px">'.
                    '<span style="font-size:12px;color:#9ca3af">Preț vânzare:</span> '.$priceHtml.'</p>' : '')
                .'</div>';
        }

        $sectionTitle = $count > 0
            ? '<p style="font-size:12px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:0.1em;margin-bottom:8px">Necesare</p>'
            : '';

        $html = <<<HTML
<div style="display:inline-block;position:relative">
    <button type="button"
            onclick="var m=this.nextElementSibling;m.style.cssText='display:flex;position:fixed;inset:0;z-index:9999;align-items:center;justify-content:center;padding:1rem;background:rgba(17,24,39,.6)'"
            style="display:inline-flex;align-items:center;gap:4px;border-radius:6px;padding:2px 8px;font-size:12px;font-weight:500;background:#fdf2f2;color:#8B1A1A;border:1px solid #e8c4c4;cursor:pointer;transition:background .15s">
        <svg style="width:12px;height:12px;flex-shrink:0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        {$label}
    </button>

    <div data-po-modal style="display:none"
         onclick="if(event.target===this)this.style.display='none'">

        <div onclick="event.stopPropagation()"
             style="position:relative;width:100%;max-width:32rem;background:white;border-radius:12px;box-shadow:0 20px 60px rgba(0,0,0,.25);overflow:hidden">

            <div style="display:flex;align-items:center;justify-content:space-between;padding:16px 24px;border-bottom:1px solid #e5e7eb">
                <h3 style="font-size:15px;font-weight:600;color:#111827;margin:0">Detalii cantitate</h3>
                <button type="button"
                        onclick="this.closest('[data-po-modal]').style.display='none'"
                        style="display:flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:6px;background:none;border:none;cursor:pointer;color:#9ca3af">
                    <svg style="width:20px;height:20px" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            {$productHeaderHtml}

            <div style="padding:20px 24px;max-height:70vh;overflow-y:auto;display:flex;flex-direction:column;gap:12px">
                {$sectionTitle}
                {$rows}
                {$recSection}
            </div>
        </div>
    </div>
</div>
HTML;

        return new HtmlString($html);
    }
}
