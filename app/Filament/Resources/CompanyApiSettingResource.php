<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CompanyApiSettingResource\Pages;
use App\Models\CompanyApiSetting;
use App\Models\User;
use App\Services\CompanyData\OpenApiCompanyLookupService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Throwable;

class CompanyApiSettingResource extends Resource
{
    protected static ?string $model = CompanyApiSetting::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationGroup = 'Setări';

    protected static ?string $navigationLabel = 'API date firmă';

    protected static ?string $modelLabel = 'setare API date firmă';

    protected static ?string $pluralModelLabel = 'setări API date firmă';

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
        if (! (static::currentUser()?->isAdmin() ?? false)) {
            return false;
        }

        return ! CompanyApiSetting::query()
            ->where('provider', CompanyApiSetting::PROVIDER_OPENAPI)
            ->exists();
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
                Forms\Components\Hidden::make('provider')
                    ->default(CompanyApiSetting::PROVIDER_OPENAPI)
                    ->dehydrated(),
                Forms\Components\TextInput::make('name')
                    ->label('Nume conexiune')
                    ->required()
                    ->default('OpenAPI.ro')
                    ->maxLength(255),
                Forms\Components\TextInput::make('base_url')
                    ->label('Base URL')
                    ->required()
                    ->default('https://api.openapi.ro')
                    ->url()
                    ->maxLength(2048),
                Forms\Components\TextInput::make('api_key')
                    ->label('API key')
                    ->password()
                    ->revealable()
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->helperText('Cheia este trimisă către OpenAPI prin header-ul x-api-key.')
                    ->maxLength(65535),
                Forms\Components\TextInput::make('timeout')
                    ->label('Timeout (sec)')
                    ->numeric()
                    ->required()
                    ->default(30)
                    ->minValue(5)
                    ->maxValue(300),
                Forms\Components\Toggle::make('verify_ssl')
                    ->label('Verify SSL')
                    ->default(true),
                Forms\Components\Toggle::make('is_active')
                    ->label('Activă')
                    ->default(true),
            ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nume')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('base_url')
                    ->label('Base URL')
                    ->limit(60)
                    ->searchable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Activă')
                    ->boolean(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Actualizat')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->actions([
                Tables\Actions\Action::make('test')
                    ->label('Test API')
                    ->icon('heroicon-o-signal')
                    ->color('info')
                    ->action(function (CompanyApiSetting $record): void {
                        try {
                            app(OpenApiCompanyLookupService::class)->testConnection($record);

                            Notification::make()
                                ->success()
                                ->title('Conexiune validă')
                                ->body('API-ul OpenAPI a răspuns corect.')
                                ->send();
                        } catch (Throwable $exception) {
                            Notification::make()
                                ->danger()
                                ->title('Conexiune eșuată')
                                ->body($exception->getMessage())
                                ->send();
                        }
                    }),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
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
            'index' => Pages\ListCompanyApiSettings::route('/'),
            'create' => Pages\CreateCompanyApiSetting::route('/create'),
            'edit' => Pages\EditCompanyApiSetting::route('/{record}/edit'),
        ];
    }
}
