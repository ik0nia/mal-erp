<?php

namespace App\Filament\App\Resources\SupplierResource\RelationManagers;

use App\Models\RolePermission;
use App\Models\SupplierFeed;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Actions;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class FeedsRelationManager extends RelationManager
{
    protected static string $relationship = 'feeds';
    protected static ?string $title       = 'Feed-uri prețuri';
    protected static ?string $modelLabel  = 'Feed';

    public static function shouldSkipAuthorization(): bool
    {
        return true;
    }

    public static function canViewForRecord(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): bool
    {
        $user = Auth::user();
        if (! $user) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        return RolePermission::check($user, 'supplier_feeds_view');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->schema([
            Forms\Components\Select::make('provider')
                ->label('Tip feed')
                ->options(SupplierFeed::providerOptions())
                ->required()
                ->native(false)
                ->live()
                ->columnSpanFull(),

            Forms\Components\TextInput::make('label')
                ->label('Denumire feed')
                ->placeholder('ex: Feed prețuri RON')
                ->maxLength(255)
                ->columnSpanFull(),

            Forms\Components\Toggle::make('is_active')
                ->label('Activ')
                ->default(true)
                ->columnSpanFull(),

            // ── Toya API ──────────────────────────────────────────────────
            Forms\Components\Section::make('Configurare Toya API')
                ->schema([
                    Forms\Components\TextInput::make('settings.api_key')
                        ->label('API Key Toya (Pimcore)')
                        ->password()
                        ->revealable()
                        ->maxLength(500)
                        ->columnSpanFull(),
                ])
                ->visible(fn (Get $get) => $get('provider') === SupplierFeed::PROVIDER_TOYA_API)
                ->columns(1),

            // ── CSV URL ───────────────────────────────────────────────────
            Forms\Components\Section::make('Configurare Feed CSV')
                ->schema([
                    Forms\Components\TextInput::make('settings.url')
                        ->label('URL feed CSV')
                        ->url()
                        ->maxLength(500)
                        ->columnSpanFull(),
                    Forms\Components\TextInput::make('settings.delimiter')
                        ->label('Separator coloane')
                        ->placeholder(';')
                        ->maxLength(5),
                    Forms\Components\TextInput::make('settings.sku_column')
                        ->label('Coloana SKU')
                        ->placeholder('SKU')
                        ->maxLength(100),
                    Forms\Components\TextInput::make('settings.price_column')
                        ->label('Coloana preț')
                        ->placeholder('Price')
                        ->maxLength(100),
                ])
                ->visible(fn (Get $get) => $get('provider') === SupplierFeed::PROVIDER_CSV_URL)
                ->columns(2),

            // ── Formula preț (toate tipurile de feed cu preț automat) ──────
            Forms\Components\Section::make('Formula preț vânzare')
                ->description('preț_feed × (1 − discount%) × (1 + adaos%) × (1 + TVA%)')
                ->schema([
                    Forms\Components\TextInput::make('settings.discount')
                        ->label('Discount comercial (%)')
                        ->numeric()
                        ->minValue(0)->maxValue(100)
                        ->suffix('%')
                        ->placeholder('0')
                        ->helperText('Reducerea față de prețul din feed. 0 dacă prețul e deja net.'),
                    Forms\Components\TextInput::make('settings.markup')
                        ->label('Adaos comercial (%)')
                        ->numeric()
                        ->minValue(0)->maxValue(500)
                        ->suffix('%')
                        ->placeholder('30'),
                    Forms\Components\TextInput::make('settings.vat')
                        ->label('TVA (%)')
                        ->numeric()
                        ->minValue(0)->maxValue(100)
                        ->suffix('%')
                        ->placeholder('21'),
                ])
                ->visible(fn (Get $get) => in_array($get('provider'), [
                    SupplierFeed::PROVIDER_TOYA_API,
                    SupplierFeed::PROVIDER_CSV_URL,
                    SupplierFeed::PROVIDER_XLSX_UPLOAD,
                ]))
                ->columns(3),
        ])->columns(1);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('label')
            ->columns([
                Tables\Columns\TextColumn::make('provider')
                    ->label('Tip')
                    ->badge()
                    ->formatStateUsing(fn ($state) => SupplierFeed::providerOptions()[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        SupplierFeed::PROVIDER_TOYA_API    => 'success',
                        SupplierFeed::PROVIDER_CSV_URL     => 'info',
                        SupplierFeed::PROVIDER_XLSX_UPLOAD => 'warning',
                        default                            => 'gray',
                    }),
                Tables\Columns\TextColumn::make('label')
                    ->label('Denumire')
                    ->placeholder('—'),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Activ')
                    ->boolean(),
                Tables\Columns\TextColumn::make('settings.discount')
                    ->label('Discount')
                    ->suffix('%')
                    ->placeholder('0'),
                Tables\Columns\TextColumn::make('settings.markup')
                    ->label('Adaos')
                    ->suffix('%')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('settings.vat')
                    ->label('TVA')
                    ->suffix('%')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('last_sync_at')
                    ->label('Ultima sincronizare')
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('Niciodată'),
                Tables\Columns\TextColumn::make('last_sync_status')
                    ->label('Status')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'ok'      => 'success',
                        'error'   => 'danger',
                        'running' => 'warning',
                        default   => 'gray',
                    })
                    ->placeholder('—'),
            ])
            ->headerActions([
                Actions\CreateAction::make()
                    ->label('Adaugă feed'),
            ])
            ->recordActions([
                Tables\Actions\Action::make('sync')
                    ->label('Sincronizează')
                    ->icon('heroicon-o-arrow-path')
                    ->color('success')
                    ->visible(fn (SupplierFeed $record) => $record->is_active
                        && $record->provider === SupplierFeed::PROVIDER_TOYA_API)
                    ->requiresConfirmation()
                    ->modalHeading('Pornești sincronizarea prețurilor?')
                    ->modalDescription('Se va prelua feed-ul Toya și se vor actualiza prețurile de achiziție și vânzare.')
                    ->action(function (SupplierFeed $record): void {
                        \App\Jobs\SyncToyaPricesJob::dispatch($record->id);
                        Notification::make()
                            ->title('Sincronizare pornită')
                            ->body('Job-ul rulează în fundal.')
                            ->success()
                            ->send();
                    }),

                Actions\EditAction::make()
                    ->label('Editează')
                    ->button(),

                Actions\DeleteAction::make()
                    ->label('Șterge')
                    ->button()
                    ->color('danger'),
            ]);
    }
}
