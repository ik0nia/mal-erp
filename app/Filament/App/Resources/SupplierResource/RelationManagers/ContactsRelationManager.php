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
    protected static ?string $title = 'Persoane de contact';
    protected static ?string $modelLabel = 'persoană de contact';
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
                ->placeholder('ex: Agent comercial, Director vânzări')
                ->maxLength(255),

            Forms\Components\Toggle::make('is_primary')
                ->label('Contact principal')
                ->default(false),

            Forms\Components\TextInput::make('phone')
                ->label('Telefon')
                ->tel()
                ->maxLength(50),

            Forms\Components\TextInput::make('email')
                ->label('Email')
                ->email()
                ->maxLength(255),

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
                    ->searchable(),

                Tables\Columns\TextColumn::make('role')
                    ->label('Rol')
                    ->color('gray'),

                Tables\Columns\TextColumn::make('phone')
                    ->label('Telefon')
                    ->copyable()
                    ->icon('heroicon-o-phone'),

                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->copyable()
                    ->icon('heroicon-o-envelope'),

                Tables\Columns\IconColumn::make('is_primary')
                    ->label('Principal')
                    ->boolean(),
            ])
            ->defaultSort('is_primary', 'desc')
            ->headerActions([
                Tables\Actions\CreateAction::make()->label('Adaugă contact'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }
}
