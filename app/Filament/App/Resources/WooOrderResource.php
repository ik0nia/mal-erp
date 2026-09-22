<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Concerns\EnforcesLocationScope;
use App\Filament\App\Concerns\ChecksRolePermissions;
use App\Filament\App\Concerns\HasDynamicNavSort;
use App\Filament\App\Resources\WooOrderResource\Pages;
use App\Models\ProductStock;
use App\Models\WooOrder;
use App\Models\WooProduct;
use App\Models\IntegrationConnection;
use App\Services\Courier\SamedayAwbService;
use Filament\Support\Enums\TextSize;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Schemas\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Actions;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

class WooOrderResource extends Resource
{
    use HasDynamicNavSort;

    use EnforcesLocationScope, ChecksRolePermissions;

    protected static ?string $model = WooOrder::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shopping-cart';

    protected static string|\UnitEnum|null $navigationGroup = 'Comenzi';

    protected static ?string $navigationLabel = 'Comenzi Online';

    protected static ?string $modelLabel = 'Comandă Online';

    protected static ?string $pluralModelLabel = 'Comenzi Online';

    protected static ?int $navigationSort = 10;

    /** Badge în meniu: câte comenzi sunt în procesare (necesită atenție). */
    public static function getNavigationBadge(): ?string
    {
        $count = WooOrder::where('status', 'processing')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Comenzi în procesare';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canView(Model $record): bool
    {
        return auth()->check();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('number')
                    ->label('Comandă')
                    ->formatStateUsing(fn (WooOrder $record): string => '#'.$record->number)
                    ->description(fn (WooOrder $record): ?HtmlString => $record->order_date ? new HtmlString('<span style="font-size:11px;opacity:.75">'.$record->order_date->format('d.m.Y H:i').'</span>') : null)
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => WooOrder::STATUS_COLORS[$state] ?? 'gray')
                    ->formatStateUsing(fn (string $state): string => WooOrder::STATUS_LABELS[$state] ?? $state),

                Tables\Columns\TextColumn::make('customer_name')
                    ->label('Client')
                    ->getStateUsing(fn (WooOrder $record): string => $record->customer_name)
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where(function (Builder $q) use ($search): void {
                            $q->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(billing, '$.first_name')) LIKE ?", ["%{$search}%"])
                              ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(billing, '$.last_name')) LIKE ?", ["%{$search}%"]);
                        });
                    }),

                Tables\Columns\TextColumn::make('customer_email')
                    ->label('Email')
                    ->getStateUsing(fn (WooOrder $record): string => $record->customer_email)
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(billing, '$.email')) LIKE ?", ["%{$search}%"]);
                    })
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('customer_phone')
                    ->label('Telefon')
                    ->icon('heroicon-m-phone')
                    ->getStateUsing(fn (WooOrder $record): ?string => $record->customer_phone ?: data_get($record->billing, 'phone'))
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        $digits = preg_replace('/\D+/', '', $search);
                        return $query->orWhereRaw("REPLACE(REPLACE(JSON_UNQUOTE(JSON_EXTRACT(billing, '$.phone')), ' ', ''), '-', '') LIKE ?", ["%{$digits}%"]);
                    })
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('total')
                    ->label('Total')
                    ->formatStateUsing(fn (WooOrder $record): string => number_format((float) $record->total, 2).' '.$record->currency)
                    ->description(fn (WooOrder $record): ?string => $record->payment_method_title ?: null)
                    ->sortable(),

                Tables\Columns\TextColumn::make('payment_method_title')
                    ->label('Plată')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('order_date')
                    ->label('Data')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('awb_info')
                    ->label('AWB')
                    ->placeholder('—')
                    ->getStateUsing(function (WooOrder $record): ?string {
                        $awb = $record->samedayAwbs
                            ->filter(fn ($a) => filled($a->awb_number) && $a->status !== 'cancelled')
                            ->sortByDesc('id')->first();

                        return $awb?->awb_number;
                    })
                    ->description(function (WooOrder $record): ?string {
                        $awb = $record->samedayAwbs
                            ->filter(fn ($a) => filled($a->awb_number) && $a->status !== 'cancelled')
                            ->sortByDesc('id')->first();

                        return $awb?->courier_status
                            ? $awb->courier_status . ($awb->courier_status_at ? ' · ' . $awb->courier_status_at->format('d.m H:i') : '')
                            : null;
                    })
                    ->copyable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('winmentor_sync_status')
                    ->label('WinMentor')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'synced' => 'Trimisă',
                        'failed' => 'Eșuat',
                        default  => 'Netrimisă',
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'synced' => 'success',
                        'failed' => 'danger',
                        default  => 'gray',
                    }),
                Tables\Columns\TextColumn::make('winmentor_invoice_nr')
                    ->label('Facturat')
                    ->badge()
                    ->placeholder('—')
                    ->formatStateUsing(function (?string $state, WooOrder $record): string {
                        if (! $state) {
                            return '—';
                        }
                        $serie = $record->winmentor_invoice_serie ?: $state;
                        $diff = abs((float) $record->total - (float) $record->winmentor_invoice_total);
                        if ($diff > 0.05) {
                            return '⚠ ' . $serie;
                        }
                        return $record->winmentor_invoice_estimat ? '≈ ' . $serie : $serie;
                    })
                    ->color(function (?string $state, WooOrder $record): string {
                        if (! $state) {
                            return 'gray';
                        }
                        $diff = abs((float) $record->total - (float) $record->winmentor_invoice_total);
                        return $diff > 0.05 ? 'danger' : 'success';
                    })
                    ->tooltip(fn (WooOrder $record): ?string => $record->winmentor_invoice_nr
                        ? ($record->winmentor_invoice_estimat ? 'Asociere estimată (euristic: total+dată+localitate)' : 'Asociere confirmată (ștampilă ERP)')
                        : null)
                    ->url(fn (WooOrder $record): ?string => $record->winmentor_invoice_nr
                        ? \App\Filament\App\Pages\WinmentorVanzariDetailPage::getUrl([
                            'nr'   => $record->winmentor_invoice_nr,
                            'an'   => $record->winmentor_invoice_an,
                            'luna' => $record->winmentor_invoice_luna,
                        ])
                        : null)
                    ->openUrlInNewTab(),
            ])
            ->defaultSort('order_date', 'desc')
            ->defaultPaginationPageOption(25)
            ->poll('30s')
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(WooOrder::STATUS_LABELS),

                Tables\Filters\Filter::make('order_date')
                    ->label('Dată comandă')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('from')->label('De la'),
                        \Filament\Forms\Components\DatePicker::make('until')->label('Până la'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'], fn (Builder $q, string $d): Builder => $q->whereDate('order_date', '>=', $d))
                            ->when($data['until'], fn (Builder $q, string $d): Builder => $q->whereDate('order_date', '<=', $d));
                    }),
            ])
            ->deferFilters(false)
            ->searchPlaceholder('Caută număr, client...')
            ->recordActions([
                Actions\ViewAction::make(),
                Actions\Action::make('awb_history')
                    ->label('Istoric AWB')
                    ->icon('heroicon-o-clock')
                    ->color('gray')
                    ->visible(fn (WooOrder $record): bool => $record->samedayAwbs
                        ->contains(fn ($a) => filled($a->awb_number) && $a->status !== 'cancelled'))
                    ->modalHeading(function (WooOrder $record): string {
                        $awb = $record->samedayAwbs->filter(fn ($a) => filled($a->awb_number) && $a->status !== 'cancelled')->sortByDesc('id')->first();

                        return 'Tracking AWB ' . ($awb?->awb_number ?? '');
                    })
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Închide')
                    ->modalWidth('lg')
                    ->modalContent(function (WooOrder $record) {
                        $awb = $record->samedayAwbs->filter(fn ($a) => filled($a->awb_number) && $a->status !== 'cancelled')->sortByDesc('id')->first();
                        if (! $awb) {
                            return view('filament.app.awb-tracking-timeline', ['error' => 'Comanda nu are AWB valid.', 'awb' => null]);
                        }
                        // încheiat + istoric salvat → local, fără apel Sameday
                        if ($awb->isTrackingTerminal() && filled($awb->tracking_history)) {
                            return view('filament.app.awb-tracking-timeline', ['tracking' => $awb->tracking_history, 'awb' => $awb]);
                        }
                        try {
                            $connection = $awb->connection
                                ?? IntegrationConnection::find($awb->integration_connection_id)
                                ?? IntegrationConnection::where('provider', IntegrationConnection::PROVIDER_SAMEDAY)->where('is_active', true)->first();
                            $tracking = app(SamedayAwbService::class)->getAwbStatusHistory($connection, $awb->awb_number);
                            $last = $tracking['history'][0] ?? null;
                            $deliveredAt = $tracking['summary']['delivered_at'] ?? null;
                            $pickedUp = collect($tracking['history'])
                                ->filter(fn ($h) => preg_match('/ridicat/i', (string) ($h['label'] ?? '')))
                                ->pluck('date')->filter()->sort()->first();
                            $label = $last['label'] ?? $awb->courier_status;
                            if ($deliveredAt && ! preg_match('/rambur|retur/i', (string) $label)) {
                                $label = 'Livrat — ' . $deliveredAt . ((float) $awb->cod_amount > 0 ? ' (ramburs în așteptare)' : '');
                            }
                            $awb->update([
                                'courier_status'    => $label,
                                'courier_status_at' => $last['date'] ?? $awb->courier_status_at,
                                'picked_up_at'      => $pickedUp ?? $awb->picked_up_at,
                                'delivered_at'      => $deliveredAt ?? $awb->delivered_at,
                                'tracking_history'  => $tracking,
                            ]);

                            return view('filament.app.awb-tracking-timeline', ['tracking' => $tracking, 'awb' => $awb->fresh()]);
                        } catch (\Throwable $e) {
                            if (filled($awb->tracking_history)) {
                                return view('filament.app.awb-tracking-timeline', ['tracking' => $awb->tracking_history, 'awb' => $awb]);
                            }

                            return view('filament.app.awb-tracking-timeline', ['error' => 'Tracking indisponibil: ' . $e->getMessage(), 'awb' => $awb]);
                        }
                    }),
            ])
            ->bulkActions([
                Actions\BulkAction::make('merge_orders')
                    ->label('Comasează comenzi')
                    ->icon('heroicon-o-arrows-pointing-in')
                    ->color('warning')
                    // Vizibilă pentru rolurile cu drept de editare comenzi (aceeași permisiune ca editarea din ViewWooOrder).
                    ->visible(fn (): bool => \App\Models\RolePermission::check(static::class, 'can_edit'))
                    ->requiresConfirmation()
                    ->modalHeading('Comasare comenzi')
                    ->modalSubmitActionLabel('Da, comasează')
                    ->modalContent(function (\Illuminate\Database\Eloquent\Collection $records): HtmlString {
                        $p = (new \App\Services\WooCommerce\WooOrderMergeService())->preview($records);

                        if (! $p['ok']) {
                            return new HtmlString(
                                '<div style="padding:12px;border-radius:8px;background:#fef2f2;color:#b91c1c;font-size:13px">⛔ '
                                .e(implode(' ', $p['errors'])).'</div>'
                            );
                        }

                        $t    = $p['target'];
                        $secs = collect($p['secondaries'])->map(fn (WooOrder $o): string => '#'.$o->number)->implode(', ');
                        $cur  = $t->currency ?: 'RON';

                        $rows = '';
                        foreach ($p['lines'] as $l) {
                            $badge = $l['is_new']
                                ? ' <span style="font-size:10px;color:#2563eb">(adăugat)</span>'
                                : '';
                            $rows .= '<tr>'
                                .'<td style="padding:6px 8px;border-top:1px solid #e5e7eb">'.e($l['name']).$badge.'</td>'
                                .'<td style="padding:6px 8px;border-top:1px solid #e5e7eb;text-align:center;font-weight:600">×'.$l['qty'].'</td>'
                                .'<td style="padding:6px 8px;border-top:1px solid #e5e7eb;text-align:right">'.number_format($l['line_total'], 2).'</td>'
                                .'</tr>';
                        }

                        $warn = $p['warnings']
                            ? '<div style="margin-top:10px;padding:8px 10px;border-radius:6px;background:#fffbeb;color:#b45309;font-size:12px">⚠ '
                                .e(implode(' ', $p['warnings'])).'</div>'
                            : '';

                        // ── Transport: simulat pe greutatea comasată (nu rămâne cel vechi) ──
                        $weight  = (float) $p['merged_weight_kg'];
                        $srcShip = collect($p['source_shipping']);
                        $shipRef = $srcShip->map(fn ($s): string => '#'.$s['number'].' '.number_format($s['shipping'], 2))->implode(' + ');
                        $shipSum = $srcShip->sum('shipping');

                        // Transport nou = estimare Sameday pe greutatea comasată (se aplică AUTOMAT la comasare).
                        $cod  = $t->payment_method === 'cod' ? (float) $p['products_total'] : 0.0;
                        $est  = empty($p['missing_weight'])
                            ? static::estimateMergedShippingNet($t, $weight, $cod)
                            : null;

                        $estRow    = '';
                        $totalRow  = '';
                        if ($est) {
                            $estNet   = $est['net'];
                            $estCur   = $est['currency'];
                            $estRow   = '<tr><td colspan="2" style="padding:2px 8px;text-align:right;color:#6b7280">Transport nou ('
                                .rtrim(rtrim(number_format($weight, 2), '0'), '.').' kg, estimat Sameday)</td>'
                                .'<td style="padding:2px 8px;text-align:right">'.number_format($estNet, 2).' '.e($estCur).'</td></tr>';
                            $totalRow = '<tr><td colspan="2" style="padding:6px 8px;text-align:right;font-weight:700">Total estimat (net)</td>'
                                .'<td style="padding:6px 8px;text-align:right;font-weight:700">'.number_format($p['products_total'] + $estNet, 2).' '.e($estCur).'</td></tr>';
                        }

                        $weightTxt = $weight > 0
                            ? rtrim(rtrim(number_format($weight, 2), '0'), '.').' kg'
                            : 'necunoscută';
                        $missTxt = ! empty($p['missing_weight'])
                            ? ' <span style="color:#b45309">(fără greutate: '.e(implode(', ', $p['missing_weight'])).')</span>'
                            : '';

                        // Bannerul de transport: ce se întâmplă cu taxa.
                        if ($est) {
                            $shipBox = '🚚 <b>Transport recalculat automat</b> pentru coletul comasat (~'.$weightTxt.'): '
                                .'<b>'.number_format($est['net'], 2).' '.e($est['currency']).' net</b> (estimare Sameday, ramburs inclus). '
                                .'Nu rămâne cel vechi și nu e suma lor ('.e($shipRef).' = '.number_format($shipSum, 2).'). '
                                .'Îl poți ajusta ulterior din „Editează transportul".';
                        } else {
                            $shipBox = '🚚 <b>Transport (colet comasat ~'.$weightTxt.')</b>'.$missTxt.'<br>'
                                .'Estimarea Sameday nu e disponibilă acum — transportul rămâne cel al comenzii principale și trebuie ajustat manual din „Editează transportul". '
                                .'Nu e suma lor ('.e($shipRef).' = '.number_format($shipSum, 2).').';
                        }

                        return new HtmlString(
                            '<div style="font-size:13px;line-height:1.5">'
                            .'<p>Se păstrează comanda cea mai veche <b>#'.e($t->number).'</b>'
                            .($t->order_date ? ' din '.e($t->order_date->format('d.m.Y H:i')) : '').', '
                            .'iar produsele din <b>'.e($secs).'</b> se mută în ea. '
                            .'Comenzile mutate se anulează cu notă de trasabilitate.</p>'
                            .'<p style="margin-top:8px;font-weight:600">Așa va arăta comanda #'.e($t->number).':</p>'
                            .'<table style="width:100%;border-collapse:collapse;margin-top:4px">'
                            .'<thead><tr style="font-size:11px;color:#6b7280;text-transform:uppercase">'
                            .'<th style="text-align:left;padding:0 8px">Produs</th>'
                            .'<th style="text-align:center;padding:0 8px">Cant.</th>'
                            .'<th style="text-align:right;padding:0 8px">Total ('.e($cur).')</th>'
                            .'</tr></thead><tbody>'.$rows.'</tbody>'
                            .'<tfoot>'
                            .'<tr><td colspan="2" style="padding:6px 8px;border-top:2px solid #d1d5db;text-align:right;font-weight:600">Total produse</td>'
                            .'<td style="padding:6px 8px;border-top:2px solid #d1d5db;text-align:right;font-weight:600">'.number_format($p['products_total'], 2).' '.e($cur).'</td></tr>'
                            .$estRow
                            .$totalRow
                            .'</tfoot></table>'
                            .'<div style="margin-top:10px;padding:8px 10px;border-radius:6px;background:#eff6ff;color:#1e3a8a;font-size:12px">'.$shipBox.'</div>'
                            .'<p style="margin-top:6px;font-size:11px;color:#6b7280">TVA-ul pe transport îl adaugă WooCommerce peste net. Acțiune ireversibilă printr-un click.</p>'
                            .$warn
                            .'</div>'
                        );
                    })
                    ->action(function (\Illuminate\Database\Eloquent\Collection $records): void {
                        $service = new \App\Services\WooCommerce\WooOrderMergeService();
                        $preview = $service->preview($records);

                        if (! $preview['ok']) {
                            \Filament\Notifications\Notification::make()
                                ->danger()->title('Nu se pot comasa')
                                ->body(implode(' ', $preview['errors']))->persistent()->send();

                            return;
                        }

                        $target = $preview['target'];
                        // Transport nou pe greutatea comasată (best-effort; null → merge lasă transportul vechi).
                        $cod     = $target->payment_method === 'cod' ? (float) $preview['products_total'] : 0.0;
                        $est     = empty($preview['missing_weight'])
                            ? static::estimateMergedShippingNet($target, (float) $preview['merged_weight_kg'], $cod)
                            : null;

                        try {
                            $result = $service->merge($target, $preview['secondaries'], auth()->user(), $est['net'] ?? null);
                        } catch (\Throwable $e) {
                            \Filament\Notifications\Notification::make()
                                ->danger()->title('Eroare la comasare')
                                ->body($e->getMessage())->persistent()->send();

                            return;
                        }

                        \Filament\Notifications\Notification::make()
                            ->{$result['ok'] ? 'success' : 'danger'}()
                            ->title($result['ok'] ? 'Comenzi comasate' : 'Comasare eșuată')
                            ->body($result['message'])->persistent()->send();
                    }),
                Actions\BulkAction::make('resync_selected')
                    ->label('Resync selectate')
                    ->icon('heroicon-o-cloud-arrow-down')
                    ->requiresConfirmation()
                    ->action(function (\Illuminate\Database\Eloquent\Collection $records): void {
                        foreach ($records as $order) {
                            try {
                                $client = new \App\Services\WooCommerce\WooClient($order->connection);
                                $raw    = $client->getOrder((int) $order->woo_id);
                                if (! empty($raw)) {
                                    $order->update([
                                        'status'  => (string) ($raw['status'] ?? $order->status),
                                        'total'   => (float) ($raw['total'] ?? $order->total),
                                        'data'    => $raw,
                                    ]);
                                }
                            } catch (\Throwable) {
                                // Continue with next
                            }
                        }
                        \Filament\Notifications\Notification::make()->success()->title('Resync finalizat')->send();
                    }),
            ]);
    }

    /**
     * Estimează costul de transport NET pentru comanda comasată, pe greutatea totală,
     * via Sameday (best-effort). Reutilizat de popup-ul de confirmare și de execuția merge-ului.
     *
     * @return array{net: float, currency: string}|null
     */
    public static function estimateMergedShippingNet(WooOrder $target, float $weight, float $cod): ?array
    {
        if ($weight <= 0) {
            return null;
        }

        try {
            $conn = IntegrationConnection::where('provider', IntegrationConnection::PROVIDER_SAMEDAY)
                ->where('is_active', true)->first();
            if (! $conn) {
                return null;
            }

            $input = \App\Filament\App\Resources\SamedayAwbResource::orderPrefillData($target)['prefill'];
            $input['package_weight_kg'] = $weight;
            $input['parcels']           = [['weight_kg' => $weight]];
            $input['cod_amount']        = max(0, $cod);

            $est = app(SamedayAwbService::class)->estimateAwbCost($conn, $input);

            return ! empty($est['cost'])
                ? ['net' => round((float) $est['cost'], 2), 'currency' => $est['currency'] ?? 'RON']
                : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                \Filament\Infolists\Components\ViewEntry::make('order_header')
                    ->label('')
                    ->columnSpanFull()
                    ->view('filament.app.woo-order-header'),

                Section::make('Produse')
                    ->columnSpanFull()
                    ->schema([
                        \Filament\Infolists\Components\ViewEntry::make('items_editor')
                            ->label('')
                            ->view('filament.app.woo-order-items-editor'),
                    ]),

                Section::make('AWB-uri Sameday')
                    ->columnSpanFull()
                    ->headerActions([
                        Actions\Action::make('create_awb')
                            ->label('Creare AWB')
                            ->icon('heroicon-o-truck')
                            ->color('success')
                            ->size('sm')
                            ->visible(fn (WooOrder $record): bool => (bool) $record->woo_id)
                            ->modalHeading(fn (WooOrder $record): string => 'Creare AWB Sameday — comanda #'.$record->number)
                            ->modalDescription('Datele destinatarului, ramburs-ul și căsuța Easybox sunt precompletate din comandă; expeditorul din setările conexiunii Sameday. AWB-ul apare automat și în WooCommerce (notă + plugin Sameday).')
                            ->modalSubmitActionLabel('Creează AWB')
                            ->modalWidth('5xl')
                            ->form(\App\Filament\App\Resources\SamedayAwbResource::formComponents())
                            ->fillForm(function (WooOrder $record): array {
                                // fillForm ÎNLOCUIEȘTE starea → pornim de la default-urile complete
                                $data = \App\Filament\App\Resources\SamedayAwbResource::orderPrefillData($record);
                                \App\Filament\App\Resources\SamedayAwbResource::notifyMissingWeights($data['missing_weight']);

                                return array_merge(
                                    \App\Filament\App\Resources\SamedayAwbResource::defaultFormState(),
                                    $data['prefill']
                                );
                            })
                            ->action(function (array $data, WooOrder $record, $livewire): void {
                                $awb = app(\App\Services\Courier\SamedayAwbCreator::class)
                                    ->create($data, auth()->user(), $record);

                                \Filament\Notifications\Notification::make()
                                    ->success()
                                    ->title('AWB creat: '.$awb->awb_number)
                                    ->body('AWB-ul e vizibil pe comandă în ERP și în WooCommerce (notă + plugin Sameday).')
                                    ->persistent()
                                    ->send();

                                $livewire->redirect(static::getUrl('view', ['record' => $record]));
                            }),
                    ])
                    ->schema([
                        RepeatableEntry::make('samedayAwbs')
                            ->label('')
                            ->schema([
                                TextEntry::make('awb_number')
                                    ->label('AWB')
                                    ->copyable()->copyMessage('Copiat!')->placeholder('-')
                                    ->weight('bold')
                                    ->columnSpan(3)
                                    ->hintActions([
                                        Actions\Action::make('print_a6')
                                            ->label('Print A6')
                                            ->icon('heroicon-o-printer')
                                            ->color('primary')
                                            ->visible(fn ($record): bool => filled($record->awb_number) && ! in_array($record->status, ['cancelled', 'failed'], true))
                                            ->url(fn ($record): string => route('awb.pdf', ['awb' => $record->id, 'format' => 'A6']))
                                            ->openUrlInNewTab(),
                                        Actions\Action::make('print_a4')
                                            ->label('A4')
                                            ->icon('heroicon-o-document')
                                            ->color('gray')
                                            ->visible(fn ($record): bool => filled($record->awb_number) && ! in_array($record->status, ['cancelled', 'failed'], true))
                                            ->url(fn ($record): string => route('awb.pdf', ['awb' => $record->id, 'format' => 'A4']))
                                            ->openUrlInNewTab(),
                                    ]),
                                TextEntry::make('status')
                                    ->label('Status ERP')
                                    ->badge()
                                    ->columnSpan(2)
                                    ->color(fn (string $state): string => match ($state) {
                                        'created' => 'success',
                                        'cancelled' => 'gray',
                                        'failed' => 'danger',
                                        default => 'gray',
                                    }),
                                TextEntry::make('courier_status')
                                    ->label('Status curier')
                                    ->placeholder('neverificat')
                                    ->columnSpan(3)
                                    ->getStateUsing(fn ($record): ?string => $record->courier_status
                                        ? $record->courier_status . ($record->courier_status_at ? ' (' . $record->courier_status_at->format('d.m H:i') . ')' : '')
                                        : null)
                                    ->hintAction(
                                        Actions\Action::make('refresh_status')
                                            ->label('Istoric')
                                            ->icon('heroicon-o-clock')
                                            ->visible(fn ($record): bool => filled($record->awb_number))
                                            ->modalHeading(fn ($record): string => 'Tracking AWB ' . $record->awb_number)
                                            ->modalSubmitAction(false)
                                            ->modalCancelActionLabel('Închide')
                                            ->modalWidth('lg')
                                            ->modalContent(function ($record) {
                                                // tracking încheiat + istoric salvat → servim local, fără apel Sameday
                                                if ($record->isTrackingTerminal() && filled($record->tracking_history)) {
                                                    return view('filament.app.awb-tracking-timeline', ['tracking' => $record->tracking_history, 'awb' => $record]);
                                                }
                                                try {
                                                    $connection = $record->connection
                                                        ?? IntegrationConnection::find($record->integration_connection_id)
                                                        ?? IntegrationConnection::where('provider', IntegrationConnection::PROVIDER_SAMEDAY)->where('is_active', true)->first();
                                                    $tracking = app(SamedayAwbService::class)->getAwbStatusHistory($connection, $record->awb_number);
                                                    $last = $tracking['history'][0] ?? null;
                                                    $deliveredAt = $tracking['summary']['delivered_at'] ?? null;
                                                    $pickedUp = collect($tracking['history'])
                                                        ->filter(fn ($h) => preg_match('/ridicat/i', (string) ($h['label'] ?? '')))
                                                        ->pluck('date')->filter()->sort()->first();
                                                    $label = $last['label'] ?? $record->courier_status;
                                                    if ($deliveredAt && ! preg_match('/rambur|retur/i', (string) $label)) {
                                                        $label = 'Livrat — ' . $deliveredAt . ((float) $record->cod_amount > 0 ? ' (ramburs în așteptare)' : '');
                                                    }
                                                    $record->update([
                                                        'courier_status'    => $label,
                                                        'courier_status_at' => $last['date'] ?? $record->courier_status_at,
                                                        'picked_up_at'      => $pickedUp ?? $record->picked_up_at,
                                                        'delivered_at'      => $deliveredAt ?? $record->delivered_at,
                                                        'tracking_history'  => $tracking,
                                                    ]);

                                                    return view('filament.app.awb-tracking-timeline', ['tracking' => $tracking, 'awb' => $record->fresh()]);
                                                } catch (\Throwable $e) {
                                                    // fallback: istoric salvat local în loc de eroare
                                                    if (filled($record->tracking_history)) {
                                                        return view('filament.app.awb-tracking-timeline', ['tracking' => $record->tracking_history, 'awb' => $record]);
                                                    }

                                                    return view('filament.app.awb-tracking-timeline', ['error' => 'Tracking indisponibil: ' . $e->getMessage(), 'awb' => $record]);
                                                }
                                            })
                                    ),
                                TextEntry::make('package_info')
                                    ->label('Colete / Ramburs')
                                    ->columnSpan(2)
                                    ->getStateUsing(fn ($record): string => (int) $record->package_count . ' colet(e), ' . rtrim(rtrim(number_format((float) $record->package_weight_kg, 2), '0'), '.') . ' kg'
                                        . ((float) $record->cod_amount > 0 ? ' · ramburs ' . number_format((float) $record->cod_amount, 2) . ' RON' : ''))
                                    ->hintAction(
                                        Actions\Action::make('update_cod')
                                            ->label('Modifică ramburs')
                                            ->icon('heroicon-o-banknotes')
                                            ->color('warning')
                                            // are sens doar pe AWB activ, nelivrat încă
                                            ->visible(fn ($record): bool => filled($record->awb_number)
                                                && $record->status === 'created'
                                                && ! $record->delivered_at
                                                && ! $record->isTrackingTerminal())
                                            ->schema([
                                                \Filament\Forms\Components\TextInput::make('cod_amount')
                                                    ->label('Sumă ramburs (RON)')
                                                    ->numeric()->minValue(0)->required()
                                                    ->default(fn ($record) => (float) $record->cod_amount)
                                                    ->helperText('0 = fără ramburs. Se trimite direct la Sameday.'),
                                            ])
                                            ->action(function (array $data, $record): void {
                                                try {
                                                    $connection = $record->connection
                                                        ?? IntegrationConnection::find($record->integration_connection_id)
                                                        ?? IntegrationConnection::where('provider', IntegrationConnection::PROVIDER_SAMEDAY)->where('is_active', true)->first();
                                                    app(SamedayAwbService::class)->updateCodAmount($connection, $record->awb_number, (float) $data['cod_amount']);
                                                    $record->update(['cod_amount' => (float) $data['cod_amount']]);
                                                    \Filament\Notifications\Notification::make()
                                                        ->title('Ramburs actualizat la Sameday')
                                                        ->body(number_format((float) $data['cod_amount'], 2) . ' RON pe AWB ' . $record->awb_number)
                                                        ->success()->send();
                                                } catch (\Throwable $e) {
                                                    \Filament\Notifications\Notification::make()
                                                        ->title('Nu am putut modifica rambursul')
                                                        ->body($e->getMessage())
                                                        ->danger()->send();
                                                }
                                            })
                                    ),
                                TextEntry::make('created_at')->label('Creat la')->dateTime('d.m.Y H:i')->columnSpan(2),
                                TextEntry::make('delivery_time')
                                    ->label('Durată livrare')
                                    ->placeholder('—')
                                    ->columnSpan(2)
                                    ->getStateUsing(function ($record): ?string {
                                        if (! $record->picked_up_at || ! $record->delivered_at) {
                                            return null;
                                        }
                                        $ore = $record->picked_up_at->diffInHours($record->delivered_at);

                                        return $ore < 48
                                            ? $ore . ' ore'
                                            : number_format($ore / 24, 1, ',', '') . ' zile';
                                    }),
                                TextEntry::make('error_message')
                                    ->label('Eroare')
                                    ->color('danger')
                                    ->columnSpanFull()
                                    ->visible(fn ($record): bool => $record->status === 'failed' && filled($record->error_message)),
                            ])
                            ->columns(12),
                    ]),
                Section::make('WinMentor')
                    ->columnSpanFull()
                    ->columns(4)
                    ->schema([
                        TextEntry::make('winmentor_sync_status')
                            ->label('Status import')
                            ->badge()
                            ->getStateUsing(fn (WooOrder $record): string => match ($record->winmentor_sync_status) {
                                'synced' => 'Trimisă în WinMentor',
                                'failed' => 'Eșuat',
                                default  => 'Netrimisă',
                            })
                            ->color(fn (WooOrder $record): string => match ($record->winmentor_sync_status) {
                                'synced' => 'success',
                                'failed' => 'danger',
                                default  => 'gray',
                            }),
                        TextEntry::make('winmentor_synced_at')
                            ->label('Data trimiterii')
                            ->dateTime('d.m.Y H:i')
                            ->placeholder('-'),
                        TextEntry::make('synced_by_name')
                            ->label('Trimisă de')
                            ->getStateUsing(fn (WooOrder $record): string => $record->syncedBy?->name ?? '-'),
                        TextEntry::make('winmentor_client_id')
                            ->label('ID client WinMentor')
                            ->placeholder('-'),
                        TextEntry::make('winmentor_sync_error')
                            ->label('Motiv eșec')
                            ->columnSpanFull()
                            ->color('danger')
                            ->visible(fn (WooOrder $record): bool => $record->winmentor_sync_status === 'failed' && ! empty($record->winmentor_sync_error)),
                    ]),

                Section::make('Istoric modificări (din ERP)')
                    ->columnSpanFull()
                    ->collapsible()
                    ->visible(fn ($record): bool => $record->edits()->exists())
                    ->schema([
                        \Filament\Infolists\Components\ViewEntry::make('edits_history')
                            ->label('')
                            ->view('filament.app.woo-order-edits-history'),
                    ]),

            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWooOrders::route('/'),
            'view'  => Pages\ViewWooOrder::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return static::applyLocationFilter(
            parent::getEloquentQuery()->with(['items.order', 'samedayAwbs', 'connection'])
        );
    }
}
