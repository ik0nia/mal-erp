<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Concerns\EnforcesLocationScope;
use App\Filament\App\Resources\WooOrderResource\Pages;
use App\Models\ProductStock;
use App\Models\WooOrder;
use App\Models\WooProduct;
use Filament\Infolists\Components\Actions\Action as InfolistAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

class WooOrderResource extends Resource
{
    use EnforcesLocationScope;

    protected static ?string $model = WooOrder::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?string $navigationGroup = 'Comenzi';

    protected static ?string $navigationLabel = 'Comenzi Online';

    protected static ?string $modelLabel = 'Comandă Online';

    protected static ?string $pluralModelLabel = 'Comenzi Online';

    protected static ?int $navigationSort = 10;

    public static function canViewAny(): bool
    {
        return auth()->check();
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
            ->searchPlaceholder('Caută număr, client...')
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('resync_selected')
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

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Section::make('Comandă')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('number')
                            ->label('Număr')
                            ->formatStateUsing(fn (WooOrder $record): string => '#'.$record->number),
                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->color(fn (string $state): string => WooOrder::STATUS_COLORS[$state] ?? 'gray')
                            ->formatStateUsing(fn (string $state): string => WooOrder::STATUS_LABELS[$state] ?? $state),
                        TextEntry::make('order_date')
                            ->label('Data comenzii')
                            ->dateTime('d.m.Y H:i:s'),
                        TextEntry::make('payment_method_title')
                            ->label('Metodă plată')
                            ->placeholder('-'),
                        TextEntry::make('date_paid')
                            ->label('Data plății')
                            ->dateTime('d.m.Y H:i:s')
                            ->placeholder('-'),
                        TextEntry::make('customer_note')
                            ->label('Notă client')
                            ->placeholder('-')
                            ->columnSpanFull(),
                    ]),

                Section::make('Client')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('billing_info')
                            ->label('Adresă facturare')
                            ->getStateUsing(function (WooOrder $record): string {
                                $b = $record->billing ?? [];
                                $lines = array_filter([
                                    trim(($b['first_name'] ?? '').' '.($b['last_name'] ?? '')),
                                    $b['company'] ?? '',
                                    $b['address_1'] ?? '',
                                    $b['address_2'] ?? '',
                                    trim(($b['postcode'] ?? '').' '.($b['city'] ?? '')),
                                    $b['state'] ?? '',
                                    $b['country'] ?? '',
                                ]);

                                return implode(', ', $lines) ?: '-';
                            })
                            ->html(false),

                        TextEntry::make('shipping_info')
                            ->label('Adresă livrare')
                            ->getStateUsing(function (WooOrder $record): string {
                                $s = $record->shipping ?? [];
                                if (empty(array_filter($s))) {
                                    return 'La fel ca facturarea';
                                }
                                $lines = array_filter([
                                    trim(($s['first_name'] ?? '').' '.($s['last_name'] ?? '')),
                                    $s['company'] ?? '',
                                    $s['address_1'] ?? '',
                                    $s['address_2'] ?? '',
                                    trim(($s['postcode'] ?? '').' '.($s['city'] ?? '')),
                                    $s['state'] ?? '',
                                    $s['country'] ?? '',
                                ]);

                                return implode(', ', $lines) ?: '-';
                            }),

                        TextEntry::make('customer_phone')
                            ->label('Telefon')
                            ->getStateUsing(fn (WooOrder $record): string => $record->customer_phone ?: '-'),

                        TextEntry::make('customer_email')
                            ->label('Email')
                            ->getStateUsing(fn (WooOrder $record): string => $record->customer_email ?: '-'),
                    ]),

                Section::make('Produse')
                    ->schema([
                        RepeatableEntry::make('items')
                            ->label('')
                            ->schema([
                                TextEntry::make('name')->label('Produs'),
                                TextEntry::make('sku')->label('SKU')->placeholder('-'),
                                TextEntry::make('quantity')->label('Cant.')->numeric(),
                                TextEntry::make('erp_stock')
                                    ->label('Stoc ERP')
                                    ->getStateUsing(function (\App\Models\WooOrderItem $record): string {
                                        if (! $record->woo_product_id) {
                                            return '–';
                                        }
                                        $locationId = (int) $record->order->location_id;
                                        $localId    = WooProduct::where('woo_id', $record->woo_product_id)->value('id');
                                        if (! $localId) {
                                            return '–';
                                        }
                                        $qty = ProductStock::where('woo_product_id', $localId)
                                            ->when($locationId > 0, fn ($q) => $q->where('location_id', $locationId))
                                            ->value('quantity');

                                        return $qty !== null ? number_format((float) $qty, 0) : '–';
                                    })
                                    ->badge()
                                    ->color(function (\App\Models\WooOrderItem $record): string {
                                        if (! $record->woo_product_id) {
                                            return 'gray';
                                        }
                                        $locationId = (int) $record->order->location_id;
                                        $localId    = WooProduct::where('woo_id', $record->woo_product_id)->value('id');
                                        if (! $localId) {
                                            return 'gray';
                                        }
                                        $qty = ProductStock::where('woo_product_id', $localId)
                                            ->when($locationId > 0, fn ($q) => $q->where('location_id', $locationId))
                                            ->value('quantity');

                                        if ($qty === null) {
                                            return 'gray';
                                        }

                                        return (float) $qty >= (int) $record->quantity ? 'success' : 'danger';
                                    }),
                                TextEntry::make('price')
                                    ->label('Preț')
                                    ->formatStateUsing(fn (mixed $state): string => number_format((float) $state, 2)),
                                TextEntry::make('total')
                                    ->label('Total')
                                    ->formatStateUsing(fn (mixed $state): string => number_format((float) $state, 2)),
                            ])
                            ->columns(6),
                    ]),

                Section::make('Totale')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('subtotal')
                            ->label('Subtotal')
                            ->formatStateUsing(fn (WooOrder $record): string => number_format((float) $record->subtotal, 2).' '.$record->currency),
                        TextEntry::make('shipping_total')
                            ->label('Livrare')
                            ->formatStateUsing(fn (WooOrder $record): string => number_format((float) $record->shipping_total, 2).' '.$record->currency),
                        TextEntry::make('discount_total')
                            ->label('Discount')
                            ->formatStateUsing(fn (WooOrder $record): string => number_format((float) $record->discount_total, 2).' '.$record->currency),
                        TextEntry::make('tax_total')
                            ->label('TVA')
                            ->formatStateUsing(fn (WooOrder $record): string => number_format((float) $record->tax_total, 2).' '.$record->currency),
                        TextEntry::make('total')
                            ->label('TOTAL')
                            ->weight(\Filament\Support\Enums\FontWeight::Bold)
                            ->size(\Filament\Infolists\Components\TextEntry\TextEntrySize::Large)
                            ->formatStateUsing(fn (WooOrder $record): string => number_format((float) $record->total, 2).' '.$record->currency),
                    ]),

                Section::make('AWB-uri Sameday')
                    ->schema([
                        RepeatableEntry::make('samedayAwbs')
                            ->label('')
                            ->schema([
                                TextEntry::make('awb_number')->label('AWB')->copyable()->placeholder('-'),
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
