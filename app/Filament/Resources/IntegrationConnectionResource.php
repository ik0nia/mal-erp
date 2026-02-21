<?php

namespace App\Filament\Resources;

use App\Filament\Resources\IntegrationConnectionResource\Pages;
use App\Jobs\ImportWooCategoriesJob;
use App\Jobs\ImportWooProductsJob;
use App\Models\IntegrationConnection;
use App\Models\Location;
use App\Models\User;
use App\Services\WooCommerce\WooClient;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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
                Hidden::make('provider')
                    ->default(IntegrationConnection::PROVIDER_WOOCOMMERCE)
                    ->dehydrated(),
                Placeholder::make('provider_display')
                    ->label('Provider')
                    ->content(IntegrationConnection::PROVIDER_WOOCOMMERCE),
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
                TextInput::make('name')
                    ->label('Nume')
                    ->required()
                    ->maxLength(255),
                TextInput::make('base_url')
                    ->label('Base URL')
                    ->required()
                    ->url()
                    ->maxLength(255),
                TextInput::make('consumer_key')
                    ->label('Consumer Key')
                    ->password()
                    ->revealable()
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->maxLength(65535),
                TextInput::make('consumer_secret')
                    ->label('Consumer Secret')
                    ->password()
                    ->revealable()
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->maxLength(65535),
                Toggle::make('verify_ssl')
                    ->label('Verify SSL')
                    ->default(true),
                Toggle::make('is_active')
                    ->label('Activă')
                    ->default(true),
                KeyValue::make('settings')
                    ->label('Settings')
                    ->keyLabel('Cheie')
                    ->valueLabel('Valoare')
                    ->columnSpanFull(),
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
                Tables\Columns\TextColumn::make('location.name')
                    ->label('Magazin')
                    ->searchable()
                    ->sortable(),
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
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Activă'),
            ])
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
                            (new WooClient($record))->testConnection();

                            Notification::make()
                                ->success()
                                ->title('Conexiune validă')
                                ->body('Conexiunea WooCommerce a răspuns cu succes.')
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
                    ->action(function (IntegrationConnection $record): void {
                        ImportWooProductsJob::dispatch($record->id);

                        Notification::make()
                            ->success()
                            ->title('Import produse pornit')
                            ->body('Job-ul de import produse a fost trimis în coadă.')
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
