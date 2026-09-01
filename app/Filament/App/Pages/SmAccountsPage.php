<?php

namespace App\Filament\App\Pages;

use App\Models\SmAccount;
use App\Services\Social\MetaSocialClient;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SmAccountsPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon  = 'heroicon-o-link';
    protected static string|\UnitEnum|null $navigationGroup = 'Social Media';
    protected static ?string $navigationLabel = 'Conturi Meta';
    protected static ?string $title           = 'Conturi Meta (Facebook & Instagram)';
    protected static ?int $navigationSort     = 21;

    protected string $view = 'filament.app.pages.sm-accounts';

    public function table(Table $table): Table
    {
        return $table
            ->query(SmAccount::query())
            ->columns([
                TextColumn::make('name')->label('Nume'),
                TextColumn::make('platform')->label('Platformă')->badge()
                    ->color(fn ($state) => $state === 'facebook' ? 'primary' : 'warning'),
                TextColumn::make('page_id')->label('Page ID')->placeholder('—'),
                TextColumn::make('instagram_business_id')->label('IG Business ID')->placeholder('—'),
                IconColumn::make('is_active')->label('Activ')->boolean(),
                TextColumn::make('token_expires_at')->label('Token expiră')->dateTime('d.m.Y')->placeholder('—'),
            ])
            ->headerActions([
                \Filament\Actions\Action::make('add_account')
                    ->label('Adaugă cont')
                    ->icon('heroicon-o-plus')
                    ->form([
                        TextInput::make('name')->label('Nume afișat')->required(),
                        Select::make('platform')
                            ->label('Platformă')
                            ->options(['facebook' => 'Facebook', 'instagram' => 'Instagram'])
                            ->required()
                            ->native(false),
                        TextInput::make('page_id')->label('Facebook Page ID'),
                        TextInput::make('instagram_business_id')->label('Instagram Business Account ID'),
                        TextInput::make('access_token')->label('Access Token')->password()->required(),
                        Toggle::make('is_active')->label('Activ')->default(true),
                    ])
                    ->action(function (array $data) {
                        SmAccount::create($data);
                        Notification::make()->title('Cont adăugat!')->success()->send();
                    }),
            ])
            ->actions([
                \Filament\Actions\Action::make('verify')
                    ->label('Verifică token')
                    ->icon('heroicon-o-shield-check')
                    ->action(function (SmAccount $record) {
                        $client = app(MetaSocialClient::class);
                        $valid  = $client->verifyToken($record->access_token);

                        if ($valid) {
                            Notification::make()->title('Token valid!')->success()->send();
                        } else {
                            Notification::make()->title('Token invalid sau expirat!')->danger()->send();
                        }
                    }),

                \Filament\Actions\Action::make('toggle_active')
                    ->label(fn (SmAccount $r) => $r->is_active ? 'Dezactivează' : 'Activează')
                    ->icon(fn (SmAccount $r) => $r->is_active ? 'heroicon-o-x-circle' : 'heroicon-o-check-circle')
                    ->action(fn (SmAccount $r) => $r->update(['is_active' => ! $r->is_active])),

                \Filament\Actions\DeleteAction::make(),
            ]);
    }
}
