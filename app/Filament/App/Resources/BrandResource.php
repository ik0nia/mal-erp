<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\BrandResource\Pages;
use App\Models\Brand;
use App\Models\Supplier;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class BrandResource extends Resource
{
    protected static ?string $model = Brand::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationGroup = 'Achiziții';

    protected static ?string $navigationLabel = 'Branduri';

    protected static ?string $modelLabel = 'Brand';

    protected static ?string $pluralModelLabel = 'Branduri';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Informații brand')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Nume brand')
                        ->required()
                        ->maxLength(255)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn ($state, Forms\Set $set) =>
                            $set('slug', Str::slug($state))
                        ),

                    Forms\Components\TextInput::make('slug')
                        ->label('Slug')
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true),

                    Forms\Components\TextInput::make('website_url')
                        ->label('Website')
                        ->url()
                        ->maxLength(255)
                        ->prefix('https://')
                        ->columnSpanFull(),

                    Forms\Components\TextInput::make('logo_url')
                        ->label('URL Logo')
                        ->url()
                        ->maxLength(500)
                        ->hint('URL direct către imaginea logo-ului')
                        ->columnSpanFull(),

                    Forms\Components\Textarea::make('description')
                        ->label('Descriere scurtă')
                        ->rows(2)
                        ->columnSpanFull(),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Activ')
                        ->default(true)
                        ->columnSpanFull(),
                ]),

            Forms\Components\Section::make('Furnizori')
                ->description('Furnizorii de la care putem comanda produse ale acestui brand')
                ->schema([
                    Forms\Components\Select::make('suppliers')
                        ->label('Furnizori asociați')
                        ->relationship('suppliers', 'name')
                        ->multiple()
                        ->preload()
                        ->searchable()
                        ->optionsLimit(50),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('logo_url')
                    ->label('Logo')
                    ->disk('public')
                    ->width(80)
                    ->height(40)
                    ->extraImgAttributes(['style' => 'object-fit: contain; max-width: 80px;'])
                    ->defaultImageUrl(null)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Brand')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('suppliers.name')
                    ->label('Furnizori')
                    ->listWithLineBreaks()
                    ->limitList(3)
                    ->placeholder('-')
                    ->color('primary'),

                Tables\Columns\TextColumn::make('website_url')
                    ->label('Website')
                    ->url(fn ($record) => $record->website_url)
                    ->openUrlInNewTab()
                    ->placeholder('-')
                    ->toggleable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Activ')
                    ->boolean(),

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

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListBrands::route('/'),
            'create' => Pages\CreateBrand::route('/create'),
            'edit'   => Pages\EditBrand::route('/{record}/edit'),
        ];
    }
}
