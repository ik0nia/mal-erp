<?php

namespace App\Filament\Resources;

use App\Filament\Resources\IntegrationConnectionResource\Pages;
use App\Jobs\ImportWinmentorCsvJob;
use App\Jobs\ImportWooCategoriesJob;
use App\Jobs\ImportWooProductsJob;
use App\Models\IntegrationConnection;
use App\Models\Location;
use App\Models\SyncRun;
use App\Models\User;
use App\Services\Courier\SamedayConnectionTester;
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
use Illuminate\Support\Facades\Bus;
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
                    ->label(fn (Get $get): string => $get('provider') === IntegrationConnection::PROVIDER_SAMEDAY ? 'API URL' : 'Base URL')
                    ->visible(fn (Get $get): bool => in_array($get('provider'), [IntegrationConnection::PROVIDER_WOOCOMMERCE, IntegrationConnection::PROVIDER_SAMEDAY], true))
                    ->required(fn (Get $get): bool => $get('provider') === IntegrationConnection::PROVIDER_WOOCOMMERCE)
                    ->url()
                    ->helperText(fn (Get $get): ?string => $get('provider') === IntegrationConnection::PROVIDER_SAMEDAY
                        ? 'Production: https://api.sameday.ro | Demo: https://sameday-api.demo.zitec.com'
                        : null)
                    ->maxLength(255),
                TextInput::make('consumer_key')
                    ->label(fn (Get $get): string => $get('provider') === IntegrationConnection::PROVIDER_SAMEDAY ? 'Sameday Username' : 'Consumer Key')
                    ->password()
                    ->revealable()
                    ->visible(fn (Get $get): bool => in_array($get('provider'), [IntegrationConnection::PROVIDER_WOOCOMMERCE, IntegrationConnection::PROVIDER_SAMEDAY], true))
                    ->required(fn (Get $get, string $operation): bool => $operation === 'create' && in_array($get('provider'), [IntegrationConnection::PROVIDER_WOOCOMMERCE, IntegrationConnection::PROVIDER_SAMEDAY], true))
                    ->dehydrated(fn (Get $get, ?string $state): bool => in_array($get('provider'), [IntegrationConnection::PROVIDER_WOOCOMMERCE, IntegrationConnection::PROVIDER_SAMEDAY], true) ? filled($state) : false)
                    ->maxLength(65535),
                TextInput::make('consumer_secret')
                    ->label(fn (Get $get): string => $get('provider') === IntegrationConnection::PROVIDER_SAMEDAY ? 'Sameday Password' : 'Consumer Secret')
                    ->password()
                    ->revealable()
                    ->visible(fn (Get $get): bool => in_array($get('provider'), [IntegrationConnection::PROVIDER_WOOCOMMERCE, IntegrationConnection::PROVIDER_SAMEDAY], true))
                    ->required(fn (Get $get, string $operation): bool => $operation === 'create' && in_array($get('provider'), [IntegrationConnection::PROVIDER_WOOCOMMERCE, IntegrationConnection::PROVIDER_SAMEDAY], true))
                    ->dehydrated(fn (Get $get, ?string $state): bool => in_array($get('provider'), [IntegrationConnection::PROVIDER_WOOCOMMERCE, IntegrationConnection::PROVIDER_SAMEDAY], true) ? filled($state) : false)
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
                        Toggle::make('settings.auto_sync_enabled')
                            ->label('Rulează import automat (scheduler)')
                            ->default(false)
                            ->helperText('Când este activ, serverul pornește importul pe intervalul de mai jos.'),
                        TextInput::make('settings.sync_interval_minutes')
                            ->label('Interval import (minute)')
                            ->numeric()
                            ->default(60)
                            ->minValue(5)
                            ->required(fn (Get $get): bool => (bool) $get('settings.auto_sync_enabled'))
                            ->helperText('Exemplu: 30 = import la fiecare 30 de minute.'),
                        TextInput::make('settings.timeout')
                            ->label('Timeout (sec)')
                            ->numeric()
                            ->default(30)
                            ->minValue(5),
                    ])
                    ->visible(fn (Get $get): bool => $get('provider') === IntegrationConnection::PROVIDER_WINMENTOR_CSV)
                    ->columns(2),
                Section::make('Setări Sameday')
                    ->schema([
                        Select::make('settings.environment')
                            ->label('Mediu API')
                            ->options([
                                'production' => 'Production',
                                'demo' => 'Demo',
                            ])
                            ->default('production')
                            ->native(false),
                        TextInput::make('settings.pickup_point_id')
                            ->label('Pickup point ID (optional)')
                            ->numeric()
                            ->minValue(1),
                        TextInput::make('settings.default_package_weight_kg')
                            ->label('Greutate implicită colet (kg)')
                            ->numeric()
                            ->default(1)
                            ->minValue(0.01),
                        TextInput::make('settings.timeout')
                            ->label('Timeout (sec)')
                            ->numeric()
                            ->default(30)
                            ->minValue(5),
                    ])
                    ->visible(fn (Get $get): bool => $get('provider') === IntegrationConnection::PROVIDER_SAMEDAY)
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
                        'queued' => 'info',
                        'success' => 'success',
                        'failed' => 'danger',
                        'running' => 'warning',
                        'cancelled' => 'gray',
                        default => 'gray',
                    })
                    ->toggleable(),
                Tables\Columns\TextColumn::make('latestSyncRun.stats.phase')
                    ->label('Fază import')
                    ->badge()
                    ->placeholder('-')
                    ->formatStateUsing(fn (?string $state): string => $state ?? '-')
                    ->color(fn (?string $state): string => match ($state) {
                        'queued' => 'info',
                        'local_import', 'queueing_price_push' => 'warning',
                        'pushing_prices' => 'primary',
                        'completed' => 'success',
                        'completed_with_errors', 'failed' => 'danger',
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
                        'queued' => 'queued',
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
                            } elseif ($record->isSameday()) {
                                app(SamedayConnectionTester::class)->testConnection($record);
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
                Tables\Actions\Action::make('import_all')
                    ->label('Import all')
                    ->icon('heroicon-o-arrow-path')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->visible(fn (IntegrationConnection $record): bool => $record->isWooCommerce())
                    ->action(function (IntegrationConnection $record): void {
                        Bus::chain([
                            new ImportWooCategoriesJob($record->id),
                            new ImportWooProductsJob($record->id),
                        ])->dispatch();

                        Notification::make()
                            ->success()
                            ->title('Import complet pornit')
                            ->body('Categorii + produse au fost trimise în coadă, în ordinea corectă.')
                            ->send();
                    }),
                Tables\Actions\Action::make('import_winmentor_stock')
                    ->label('Import stoc/preț CSV')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (IntegrationConnection $record): bool => $record->isWinmentorCsv())
                    ->action(function (IntegrationConnection $record): void {
                        $existingQueueOrRunning = SyncRun::query()
                            ->where('connection_id', $record->id)
                            ->whereIn('status', [SyncRun::STATUS_QUEUED, SyncRun::STATUS_RUNNING])
                            ->latest('id')
                            ->first();

                        if ($existingQueueOrRunning instanceof SyncRun) {
                            Notification::make()
                                ->warning()
                                ->title('Import deja în curs')
                                ->body("Există deja import #{$existingQueueOrRunning->id} cu status {$existingQueueOrRunning->status}.")
                                ->send();

                            return;
                        }

                        $run = SyncRun::query()->create([
                            'provider' => IntegrationConnection::PROVIDER_WINMENTOR_CSV,
                            'location_id' => $record->location_id,
                            'connection_id' => $record->id,
                            'type' => SyncRun::TYPE_WINMENTOR_STOCK,
                            'status' => SyncRun::STATUS_QUEUED,
                            'started_at' => now(),
                            'finished_at' => null,
                            'stats' => [
                                'phase' => 'queued',
                                'pages' => 1,
                                'created' => 0,
                                'updated' => 0,
                                'unchanged' => 0,
                                'processed' => 0,
                                'matched_products' => 0,
                                'missing_products' => 0,
                                'price_changes' => 0,
                                'name_mismatches' => 0,
                                'site_price_updates' => 0,
                                'site_price_update_failures' => 0,
                                'site_price_push_jobs' => 0,
                                'site_price_push_queued' => 0,
                                'site_price_push_processed' => 0,
                                'created_placeholders' => 0,
                                'local_started_at' => null,
                                'local_finished_at' => null,
                                'push_started_at' => null,
                                'push_finished_at' => null,
                                'last_heartbeat_at' => now()->toIso8601String(),
                                'missing_skus_sample' => [],
                                'name_mismatch_sample' => [],
                            ],
                            'errors' => [],
                        ]);

                        ImportWinmentorCsvJob::dispatch($record->id, (int) $run->id);

                        Notification::make()
                            ->success()
                            ->title('Import CSV pus în coadă')
                            ->body("Job-ul WinMentor a fost trimis în coadă (run #{$run->id}).")
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
