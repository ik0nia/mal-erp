<?php

namespace App\Filament\Resources;

use App\Filament\Resources\IntegrationConnectionResource\Pages;
use App\Jobs\ImportWinmentorCsvJob;
use App\Jobs\ImportWooCategoriesJob;
use App\Jobs\ImportWooProductsJob;
use App\Models\IntegrationConnection;
use App\Models\Location;
use App\Models\User;
use App\Services\WooCommerce\WooClient;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\HtmlString;
use Throwable;

class IntegrationConnectionResource extends Resource
{
    protected static ?string $model = IntegrationConnection::class;

    protected static ?string $navigationIcon = 'heroicon-o-link';

    protected static ?string $navigationGroup = 'Integrări';

    protected static ?string $navigationLabel = 'Conexiuni';

    protected static ?string $modelLabel = 'Conexiune';

    protected static ?string $pluralModelLabel = 'Conexiuni';

    protected static function currentUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::currentUser()?->isAdmin() ?? false;
    }

    public static function canViewAny(): bool
    {
        return static::currentUser()?->isAdmin() ?? false;
    }

    public static function canCreate(): bool
    {
        return static::currentUser()?->isAdmin() ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return static::currentUser()?->isAdmin() ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return static::currentUser()?->isAdmin() ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('location_id')
                    ->label('Magazin')
                    ->relationship(
                        name: 'location',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn (Builder $query) => $query
                            ->where('type', Location::TYPE_STORE)
                            ->where('is_active', true)
                    )
                    ->required()
                    ->searchable()
                    ->preload()
                    ->native(false),
                Select::make('provider')
                    ->label('Provider')
                    ->options(IntegrationConnection::providerOptions())
                    ->default(IntegrationConnection::PROVIDER_WOOCOMMERCE)
                    ->required()
                    ->native(false)
                    ->live(),
                TextInput::make('name')
                    ->label('Nume')
                    ->required()
                    ->maxLength(255),
                TextInput::make('base_url')
                    ->label('Base URL')
                    ->visible(fn (Get $get): bool => $get('provider') === IntegrationConnection::PROVIDER_WOOCOMMERCE)
                    ->required(fn (Get $get): bool => $get('provider') === IntegrationConnection::PROVIDER_WOOCOMMERCE)
                    ->url()
                    ->maxLength(255),
                TextInput::make('consumer_key')
                    ->label('Consumer Key')
                    ->password()
                    ->revealable()
                    ->visible(fn (Get $get): bool => $get('provider') === IntegrationConnection::PROVIDER_WOOCOMMERCE)
                    ->required(fn (Get $get, string $operation): bool => $operation === 'create' && $get('provider') === IntegrationConnection::PROVIDER_WOOCOMMERCE)
                    ->dehydrated(fn (Get $get, ?string $state): bool => $get('provider') === IntegrationConnection::PROVIDER_WOOCOMMERCE ? filled($state) : false)
                    ->maxLength(65535),
                TextInput::make('consumer_secret')
                    ->label('Consumer Secret')
                    ->password()
                    ->revealable()
                    ->visible(fn (Get $get): bool => $get('provider') === IntegrationConnection::PROVIDER_WOOCOMMERCE)
                    ->required(fn (Get $get, string $operation): bool => $operation === 'create' && $get('provider') === IntegrationConnection::PROVIDER_WOOCOMMERCE)
                    ->dehydrated(fn (Get $get, ?string $state): bool => $get('provider') === IntegrationConnection::PROVIDER_WOOCOMMERCE ? filled($state) : false)
                    ->maxLength(65535),
                Toggle::make('verify_ssl')
                    ->label('Verify SSL')
                    ->default(true),
                Toggle::make('is_active')
                    ->label('Activă')
                    ->default(true),
                Section::make('Setări WooCommerce')
                    ->schema([
                        TextInput::make('settings.per_page')
                            ->label('Per page')
                            ->numeric()
                            ->default(50)
                            ->minValue(1)
                            ->maxValue(100),
                        TextInput::make('settings.timeout')
                            ->label('Timeout (sec)')
                            ->numeric()
                            ->default(30)
                            ->minValue(5),
                    ])
                    ->visible(fn (Get $get): bool => $get('provider') === IntegrationConnection::PROVIDER_WOOCOMMERCE)
                    ->columns(2),
                Section::make('Setări WinMentor CSV')
                    ->schema([
                        TextInput::make('settings.csv_url')
                            ->label('CSV URL')
                            ->required(fn (Get $get): bool => $get('provider') === IntegrationConnection::PROVIDER_WINMENTOR_CSV)
                            ->url()
                            ->default('http://malinco.ro/prelucrare.php')
                            ->maxLength(2048),
                        TextInput::make('settings.delimiter')
                            ->label('Delimiter')
                            ->required(fn (Get $get): bool => $get('provider') === IntegrationConnection::PROVIDER_WINMENTOR_CSV)
                            ->default(',')
                            ->maxLength(3),
                        TextInput::make('settings.sku_column')
                            ->label('Coloană SKU')
                            ->required(fn (Get $get): bool => $get('provider') === IntegrationConnection::PROVIDER_WINMENTOR_CSV)
                            ->default('codextern')
                            ->maxLength(255),
                        TextInput::make('settings.name_column')
                            ->label('Coloană denumire')
                            ->required(fn (Get $get): bool => $get('provider') === IntegrationConnection::PROVIDER_WINMENTOR_CSV)
                            ->default('denumire')
                            ->maxLength(255),
                        TextInput::make('settings.quantity_column')
                            ->label('Coloană cantitate')
                            ->required(fn (Get $get): bool => $get('provider') === IntegrationConnection::PROVIDER_WINMENTOR_CSV)
                            ->default('cantitate')
                            ->maxLength(255),
                        TextInput::make('settings.price_column')
                            ->label('Coloană preț')
                            ->required(fn (Get $get): bool => $get('provider') === IntegrationConnection::PROVIDER_WINMENTOR_CSV)
                            ->default('pret')
                            ->maxLength(255),
                        Toggle::make('settings.push_price_to_site')
                            ->label('Trimite prețul către site (Woo)')
                            ->default(true),
                        TextInput::make('settings.timeout')
                            ->label('Timeout (sec)')
                            ->numeric()
                            ->default(30)
                            ->minValue(5),
                    ])
                    ->visible(fn (Get $get): bool => $get('provider') === IntegrationConnection::PROVIDER_WINMENTOR_CSV)
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nume')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('provider')
                    ->label('Provider')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => IntegrationConnection::providerOptions()[$state] ?? $state)
                    ->sortable(),
                Tables\Columns\TextColumn::make('location.name')
                    ->label('Magazin')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('latestSyncRun.type')
                    ->label('Ultim import')
                    ->badge()
                    ->placeholder('-')
                    ->formatStateUsing(function (?string $state, IntegrationConnection $record): string {
                        if ($state === null) {
                            return '-';
                        }

                        $labels = [
                            'categories' => 'Categorii',
                            'products' => 'Produse',
                            'winmentor_stock' => 'Stoc/Preț CSV',
                        ];

                        return $labels[$state] ?? $state;
                    })
                    ->toggleable(),
                Tables\Columns\TextColumn::make('latestSyncRun.status')
                    ->label('Status import')
                    ->badge()
                    ->placeholder('-')
                    ->formatStateUsing(fn (?string $state): string => $state ?? '-')
                    ->color(fn (?string $state): string => match ($state) {
                        'success' => 'success',
                        'failed' => 'danger',
                        'running' => 'warning',
                        'cancelled' => 'gray',
                        default => 'gray',
                    })
                    ->toggleable(),
                Tables\Columns\TextColumn::make('latestSyncRun.started_at')
                    ->label('Ultimul start')
                    ->dateTime('d.m.Y H:i:s')
                    ->placeholder('-')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('base_url')
                    ->label('Base URL')
                    ->searchable()
                    ->limit(50),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Activă')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Actualizat')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('provider')
                    ->label('Provider')
                    ->options(IntegrationConnection::providerOptions()),
                Tables\Filters\SelectFilter::make('latest_sync_status')
                    ->label('Status ultim import')
                    ->options([
                        'running' => 'running',
                        'success' => 'success',
                        'failed' => 'failed',
                        'cancelled' => 'cancelled',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $status = $data['value'] ?? null;

                        if (! is_string($status) || $status === '') {
                            return $query;
                        }

                        return $query->whereHas('latestSyncRun', function (Builder $syncQuery) use ($status): void {
                            $syncQuery->where('status', $status);
                        });
                    }),
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Activă'),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('latestSyncRun'))
            ->actions([
                Tables\Actions\Action::make('view')
                    ->label('View')
                    ->icon('heroicon-o-eye')
                    ->modalHeading(fn (IntegrationConnection $record): string => "Conexiune: {$record->name}")
                    ->modalSubmitAction(false)
                    ->modalContent(function (IntegrationConnection $record): HtmlString {
                        $payload = [
                            'provider' => $record->provider,
                            'name' => $record->name,
                            'location' => $record->location?->name,
                            'base_url' => $record->base_url,
                            'verify_ssl' => $record->verify_ssl,
                            'is_active' => $record->is_active,
                            'settings' => $record->settings,
                        ];

                        return new HtmlString(
                            '<pre style="white-space: pre-wrap;">'.e(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)).'</pre>'
                        );
                    }),
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('test_connection')
                    ->label('Test connection')
                    ->icon('heroicon-o-signal')
                    ->color('info')
                    ->action(function (IntegrationConnection $record): void {
                        try {
                            if ($record->isWooCommerce()) {
                                (new WooClient($record))->testConnection();
                            } elseif ($record->isWinmentorCsv()) {
                                $csvUrl = $record->csvUrl();

                                if ($csvUrl === '') {
                                    throw new \RuntimeException('CSV URL lipsește în settings.csv_url');
                                }

                                $response = Http::timeout($record->resolveTimeoutSeconds())
                                    ->withOptions(['verify' => $record->verify_ssl])
                                    ->get($csvUrl);

                                $response->throw();
                            }

                            Notification::make()
                                ->success()
                                ->title('Conexiune validă')
                                ->body('Conexiunea a răspuns cu succes.')
                                ->send();
                        } catch (Throwable $exception) {
                            Notification::make()
                                ->danger()
                                ->title('Conexiune eșuată')
                                ->body('Nu s-a putut valida conexiunea: '.$exception->getMessage())
                                ->send();
                        }
                    }),
                Tables\Actions\Action::make('import_categories')
                    ->label('Import categories')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (IntegrationConnection $record): bool => $record->isWooCommerce())
                    ->action(function (IntegrationConnection $record): void {
                        ImportWooCategoriesJob::dispatch($record->id);

                        Notification::make()
                            ->success()
                            ->title('Import categorii pornit')
                            ->body('Job-ul de import categorii a fost trimis în coadă.')
                            ->send();
                    }),
                Tables\Actions\Action::make('import_products')
                    ->label('Import products')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (IntegrationConnection $record): bool => $record->isWooCommerce())
                    ->action(function (IntegrationConnection $record): void {
                        ImportWooProductsJob::dispatch($record->id);

                        Notification::make()
                            ->success()
                            ->title('Import produse pornit')
                            ->body('Job-ul de import produse a fost trimis în coadă.')
                            ->send();
                    }),
                Tables\Actions\Action::make('import_winmentor_stock')
                    ->label('Import stoc/preț CSV')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (IntegrationConnection $record): bool => $record->isWinmentorCsv())
                    ->action(function (IntegrationConnection $record): void {
                        ImportWinmentorCsvJob::dispatch($record->id);

                        Notification::make()
                            ->success()
                            ->title('Import CSV pornit')
                            ->body('Job-ul WinMentor a fost trimis în coadă.')
                            ->send();
                    }),
                Tables\Actions\Action::make('sync_runs')
                    ->label('Sync runs')
                    ->icon('heroicon-o-clock')
                    ->url(fn (IntegrationConnection $record): string => SyncRunResource::getUrl('index', [
                        'tableFilters' => [
                            'connection_id' => ['value' => (string) $record->id],
                        ],
                    ])),
                Tables\Actions\Action::make('price_logs')
                    ->label('Price logs')
                    ->icon('heroicon-o-currency-dollar')
                    ->visible(fn (IntegrationConnection $record): bool => $record->isWinmentorCsv())
                    ->url(fn (IntegrationConnection $record): string => ProductPriceLogResource::getUrl('index', [
                        'tableFilters' => [
                            'location_id' => ['value' => (string) $record->location_id],
                            'source' => ['value' => IntegrationConnection::PROVIDER_WINMENTOR_CSV],
                        ],
                    ])),
                Tables\Actions\Action::make('view_categories')
                    ->label('Vezi categorii')
                    ->icon('heroicon-o-tag')
                    ->visible(fn (IntegrationConnection $record): bool => $record->isWooCommerce())
                    ->url(fn (IntegrationConnection $record): string => WooCategoryResource::getUrl('index', [
                        'tableFilters' => [
                            'connection_id' => ['value' => (string) $record->id],
                        ],
                    ])),
                Tables\Actions\Action::make('view_products')
                    ->label('Vezi produse')
                    ->icon('heroicon-o-shopping-bag')
                    ->visible(fn (IntegrationConnection $record): bool => $record->isWooCommerce())
                    ->url(fn (IntegrationConnection $record): string => WooProductResource::getUrl('index', [
                        'tableFilters' => [
                            'connection_id' => ['value' => (string) $record->id],
                        ],
                    ])),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListIntegrationConnections::route('/'),
            'create' => Pages\CreateIntegrationConnection::route('/create'),
            'edit' => Pages\EditIntegrationConnection::route('/{record}/edit'),
        ];
    }
}
