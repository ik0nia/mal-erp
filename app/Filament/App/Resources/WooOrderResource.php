<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Concerns\EnforcesLocationScope;
use App\Filament\App\Concerns\ChecksRolePermissions;
use App\Filament\App\Concerns\HasDynamicNavSort;
use App\Filament\App\Resources\WooOrderResource\Pages;
use App\Models\ProductStock;
use App\Models\WooOrder;
use App\Models\WooProduct;
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

                Tables\Columns\TextColumn::make('total')
                    ->label('Total')
                    ->formatStateUsing(fn (WooOrder $record): string => number_format((float) $record->total, 2).' '.$record->currency)
                    ->sortable(),

                Tables\Columns\TextColumn::make('payment_method_title')
                    ->label('Plată')
                    ->placeholder('-'),

                Tables\Columns\TextColumn::make('order_date')
                    ->label('Data')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),

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
            ])
            ->bulkActions([
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

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                \Filament\Infolists\Components\ViewEntry::make('order_header')
                    ->label('')
                    ->columnSpanFull()
                    ->view('filament.app.woo-order-header'),

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

                Section::make('Produse')
                    ->columnSpanFull()
                    ->schema([
                        \Filament\Infolists\Components\ViewEntry::make('items_editor')
                            ->label('')
                            ->view('filament.app.woo-order-items-editor'),
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
                                TextEntry::make('awb_number')->label('AWB')->copyable()->copyMessage('Copiat!')->placeholder('-'),
                                TextEntry::make('status')
                                    ->label('Status')
                                    ->badge()
                                    ->color(fn (string $state): string => match ($state) {
                                        'created' => 'success',
                                        'cancelled' => 'gray',
                                        'failed' => 'danger',
                                        default => 'gray',
                                    }),
                                TextEntry::make('created_at')->label('Creat la')->dateTime('d.m.Y H:i'),
                            ])
                            ->columns(3),
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
