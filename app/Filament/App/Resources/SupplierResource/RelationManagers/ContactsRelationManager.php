<?php

namespace App\Filament\App\Resources\SupplierResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ContactsRelationManager extends RelationManager
{
    protected static string $relationship = 'contacts';
    protected static ?string $title       = 'Persoane de contact';
    protected static ?string $modelLabel  = 'persoană de contact';
    protected static ?string $pluralModelLabel = 'persoane de contact';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')
                ->label('Nume')
                ->required()
                ->maxLength(255)
                ->columnSpanFull(),

            Forms\Components\TextInput::make('role')
                ->label('Rol / Funcție')
                ->placeholder('ex: Agent comercial, Contabilitate, Logistică')
                ->maxLength(255),

            Forms\Components\Toggle::make('is_primary')
                ->label('Contact principal')
                ->default(false),

            Forms\Components\TextInput::make('email')
                ->label('Email')
                ->email()
                ->maxLength(255),

            Forms\Components\TextInput::make('phone')
                ->label('Telefon')
                ->tel()
                ->maxLength(50),

            Forms\Components\Textarea::make('notes')
                ->label('Note')
                ->rows(2)
                ->columnSpanFull(),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nume')
                    ->weight('bold')
                    ->description(fn ($record) => $record->role)
                    ->searchable(),

                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->copyable()
                    ->icon('heroicon-o-envelope')
                    ->url(fn ($record) => $record->email ? "mailto:{$record->email}" : null),

                Tables\Columns\TextColumn::make('phone')
                    ->label('Telefon')
                    ->copyable()
                    ->icon('heroicon-o-phone'),

                Tables\Columns\TextColumn::make('email_count')
                    ->label('Emailuri')
                    ->suffix(' emailuri')
                    ->color(fn ($state) => match(true) {
                        $state >= 50 => 'success',
                        $state >= 10 => 'warning',
                        $state > 0  => 'gray',
                        default      => 'gray',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('last_seen_at')
                    ->label('Ultimul contact')
                    ->since()
                    ->sortable()
                    ->placeholder('Necunoscut'),

                Tables\Columns\BadgeColumn::make('source')
                    ->label('Sursă')
                    ->colors([
                        'success' => 'manual',
                        'info'    => 'email_discovery',
                        'warning' => 'domain_match',
                    ])
                    ->formatStateUsing(fn ($state) => match($state) {
                        'manual'          => 'Manual',
                        'email_discovery' => 'Din emailuri',
                        'domain_match'    => 'Domeniu potrivit',
                        default           => $state,
                    }),

                Tables\Columns\IconColumn::make('is_primary')
                    ->label('Principal')
                    ->boolean(),
            ])
            ->defaultSort('email_count', 'desc')
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Adaugă contact')
                    ->mutateFormDataUsing(fn (array $data) => array_merge($data, ['source' => 'manual'])),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }
}
