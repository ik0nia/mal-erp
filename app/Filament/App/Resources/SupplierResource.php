<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\SupplierResource\Pages;
use App\Filament\App\Resources\SupplierResource\RelationManagers\ContactsRelationManager;
use App\Models\Supplier;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SupplierResource extends Resource
{
    protected static ?string $model = Supplier::class;

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationGroup = 'Achiziții';

    protected static ?string $navigationLabel = 'Furnizori';

    protected static ?string $modelLabel = 'Furnizor';

    protected static ?string $pluralModelLabel = 'Furnizori';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Informații generale')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Nume furnizor')
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull(),

                    Forms\Components\FileUpload::make('logo_url')
                        ->label('Logo furnizor')
                        ->image()
                        ->disk('public')
                        ->directory('suppliers/logos')
                        ->imageResizeMode('contain')
                        ->imageCropAspectRatio('1:1')
                        ->imageResizeTargetWidth(200)
                        ->imageResizeTargetHeight(200)
                        ->columnSpanFull(),

                    Forms\Components\TextInput::make('website_url')
                        ->label('Website')
                        ->url()
                        ->maxLength(255)
                        ->prefix('https://')
                        ->columnSpanFull(),

                    Forms\Components\Textarea::make('address')
                        ->label('Adresă')
                        ->rows(2)
                        ->columnSpanFull(),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Activ')
                        ->default(true)
                        ->columnSpanFull(),
                ]),

            Forms\Components\Section::make('Date fiscale și bancare')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('vat_number')
                        ->label('CUI / CIF')
                        ->maxLength(50),
                    Forms\Components\TextInput::make('reg_number')
                        ->label('Nr. Reg. Com.')
                        ->maxLength(50),
                    Forms\Components\TextInput::make('bank_account')
                        ->label('IBAN')
                        ->maxLength(50),
                    Forms\Components\TextInput::make('bank_name')
                        ->label('Bancă')
                        ->maxLength(255),
                ]),

            Forms\Components\Section::make('Notițe')
                ->schema([
                    Forms\Components\Textarea::make('notes')
                        ->label('')
                        ->rows(3),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Furnizor')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\ImageColumn::make('brands.logo_url')
                    ->label('Branduri')
                    ->disk('public')
                    ->stacked()
                    ->limit(6)
                    ->size(36)
                    ->extraImgAttributes(['style' => 'object-fit: contain; background: white; border-radius: 4px;'])
                    ->placeholder('-')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('contacts.name')
                    ->label('Contact principal')
                    ->listWithLineBreaks()
                    ->limitList(1)
                    ->placeholder('-')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('contacts.phone')
                    ->label('Telefon')
                    ->listWithLineBreaks()
                    ->limitList(1)
                    ->placeholder('-')
                    ->copyable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('vat_number')
                    ->label('CUI')
                    ->placeholder('-')
                    ->toggleable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Activ')
                    ->boolean(),

                Tables\Columns\TextColumn::make('products_count')
                    ->label('Produse')
                    ->counts('products')
                    ->sortable(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Modificat')
                    ->dateTime('d.m.Y')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Activ'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('name');
    }

    public static function getRelations(): array
    {
        return [
            ContactsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListSuppliers::route('/'),
            'create' => Pages\CreateSupplier::route('/create'),
            'edit'   => Pages\EditSupplier::route('/{record}/edit'),
        ];
    }
}
