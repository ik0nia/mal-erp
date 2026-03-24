<?php

namespace App\Filament\App\Pages;
use App\Models\RolePermission;
use App\Filament\App\Concerns\HasDynamicNavSort;

use App\Filament\App\Concerns\EnforcesLocationScope;
use App\Models\IntegrationConnection;
use App\Models\Supplier;
use App\Models\WooProduct;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ProductsWithoutSupplier extends Page implements HasTable
{
    use HasDynamicNavSort, InteractsWithTable;
    use EnforcesLocationScope;

    protected static string|\BackedEnum|null $navigationIcon  = 'heroicon-o-link-slash';
    protected static string|\UnitEnum|null $navigationGroup = 'Achiziții';
    protected static ?string $navigationLabel = 'Fără furnizor';
    protected static ?int    $navigationSort  = 6;

    protected string $view = 'filament.app.pages.products-without-supplier';

    public static function shouldRegisterNavigation(): bool
    {
        return \App\Models\RolePermission::check(static::class, 'can_access');
    }

    public static function canAccess(): bool
    {
        return RolePermission::check(static::class, 'can_access');
    }

    public int $statTotal       = 0;
    public int $statPlaceholder = 0;
    public int $statWithStock   = 0;
    public int $statWithBrand   = 0;

    public function mount(): void
    {
        $this->computeStats();
    }

    public function computeStats(): void
    {
        $base = WooProduct::whereDoesntHave('suppliers')
            ->whereIn('connection_id', $this->getConnectionIds() ?: [0])
            ->where('product_type', WooProduct::TYPE_SHOP)
            ->where(fn ($q) => $q->whereNull('is_discontinued')->orWhere('is_discontinued', false));

        $this->statTotal       = $base->count();
        $this->statPlaceholder = (clone $base)->where('is_placeholder', true)->count();
        $this->statWithStock   = (clone $base)
            ->whereHas('stocks', fn ($q) => $q->where('quantity', '>', 0))
            ->count();
        $this->statWithBrand   = (clone $base)->whereNotNull('brand')->where('brand', '!=', '')->count();
    }

    public function table(Table $table): Table
    {
        $connectionIds = $this->getConnectionIds();

        return $table
            ->query(
                WooProduct::query()
                    ->whereDoesntHave('suppliers')
                    ->whereIn('connection_id', $connectionIds ?: [0])
                    ->where('product_type', WooProduct::TYPE_SHOP)
                    ->where(fn ($q) => $q->whereNull('is_discontinued')->orWhere('is_discontinued', false))
                    ->withSum('stocks', 'quantity')
            )
            ->columns([
                TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->fontFamily('mono')
                    ->width('120px'),

                TextColumn::make('name')
                    ->label('Produs')
                    ->searchable()
                    ->sortable()
                    ->limit(60)
                    ->tooltip(fn ($record) => $record->name)
                    ->url(fn ($record) => \App\Filament\App\Resources\WooProductResource::getUrl('view', ['record' => $record]), shouldOpenInNewTab: true),

                TextColumn::make('brand')
                    ->label('Brand')
                    ->placeholder('-')
                    ->badge()
                    ->color('primary')
                    ->sortable(),

                TextColumn::make('stocks_sum_quantity')
                    ->label('Stoc')
                    ->getStateUsing(fn ($record) => $record->stocks_sum_quantity ?? 0)
                    ->numeric(decimalPlaces: 0)
                    ->sortable(query: fn ($query, $direction) => $query->orderBy('stocks_sum_quantity', $direction))
                    ->alignRight()
                    ->color(fn ($state) => $state > 0 ? 'success' : 'gray'),

                TextColumn::make('product_type')
                    ->label('Tip')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        WooProduct::TYPE_SHOP       => 'gray',
                        WooProduct::TYPE_PRODUCTION => 'warning',
                        WooProduct::TYPE_PALLET_FEE => 'info',
                        default                     => 'gray',
                    })
                    ->formatStateUsing(fn ($state) => (WooProduct::productTypeOptions()[$state] ?? $state) . ' ✎')
                    ->action(
                        Action::make('change_type')
                            ->label('Schimbă tip produs')
                            ->icon('heroicon-o-tag')
                            ->form([
                                Select::make('product_type')
                                    ->label('Tip produs')
                                    ->options(WooProduct::productTypeOptions())
                                    ->required()
                                    ->default(fn ($record) => $record->product_type),
                            ])
                            ->action(function ($record, array $data): void {
                                $record->update(['product_type' => $data['product_type']]);
                                Notification::make()->success()->title('Tip produs actualizat')->send();
                            })
                    ),

                TextColumn::make('source')
                    ->label('Sursă')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'winmentor_csv' => 'warning',
                        'woocommerce'   => 'success',
                        default         => 'gray',
                    })
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'winmentor_csv' => 'WinMentor',
                        'woocommerce'   => 'WooCommerce',
                        default         => $state,
                    }),

                IconColumn::make('is_placeholder')
                    ->label('Placeholder')
                    ->boolean()
                    ->trueIcon('heroicon-o-clock')
                    ->falseIcon('heroicon-o-check-circle')
                    ->trueColor('warning')
                    ->falseColor('success'),
            ])
            ->recordActions([
                Action::make('assign_supplier')
                    ->label('Asociază furnizor')
                    ->icon('heroicon-o-user-plus')
                    ->modalHeading(fn ($record) => 'Asociază furnizor — ' . $record->name)
                    ->modalWidth('lg')
                    ->form([
                        Select::make('supplier_id')
                            ->label('Furnizor')
                            ->options(Supplier::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            ->required(),
                        TextInput::make('supplier_sku')
                            ->label('SKU furnizor')
                            ->placeholder('opțional'),
                        TextInput::make('purchase_price')
                            ->label('Preț achiziție (fără TVA)')
                            ->numeric()
                            ->prefix('RON')
                            ->placeholder('opțional'),
                    ])
                    ->action(function ($record, array $data, $livewire): void {
                        // Dacă produsul are deja furnizori, demarcăm preferred pe ei
                        if ($record->suppliers()->exists()) {
                            $record->suppliers()->updateExistingPivot(
                                $record->suppliers()->pluck('suppliers.id')->all(),
                                ['is_preferred' => false]
                            );
                        }

                        $record->suppliers()->attach($data['supplier_id'], [
                            'supplier_sku'   => $data['supplier_sku'] ?? null,
                            'purchase_price' => $data['purchase_price'] ?? null,
                            'currency'       => 'RON',
                            'is_preferred'   => true,
                        ]);

                        $livewire->computeStats();

                        Notification::make()
                            ->title('Furnizor asociat și marcat ca preferat')
                            ->success()
                            ->send();
                    }),
            ])
            ->recordAction('assign_supplier')
            ->filters([
                SelectFilter::make('source')
                    ->label('Sursă')
                    ->options([
                        'winmentor_csv' => 'WinMentor',
                        'woocommerce'   => 'WooCommerce',
                    ]),

                SelectFilter::make('is_placeholder')
                    ->label('Tip')
                    ->options([
                        '1' => 'Placeholder',
                        '0' => 'Produs real',
                    ]),
            ])
            ->deferFilters(false)
            ->defaultSort('name')
            ->striped()
            ->paginated([25, 50, 100]);
    }

    private function getConnectionIds(): array
    {
        $user = static::currentUser();

        if (! $user) {
            return [];
        }

        $query = IntegrationConnection::query()
            ->where('provider', IntegrationConnection::PROVIDER_WOOCOMMERCE);

        if (! $user->isSuperAdmin()) {
            $query->where(function (\Illuminate\Database\Eloquent\Builder $q) use ($user): void {
                $q->whereIn('location_id', $user->operationalLocationIds())
                  ->orWhereNull('location_id');
            });
        }

        return $query->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function bootGuardAccess(): void
    {
        if (! static::canAccess()) {
            abort(403);
        }
    }
}
