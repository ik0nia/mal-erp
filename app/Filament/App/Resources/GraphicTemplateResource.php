<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Concerns\ChecksRolePermissions;
use App\Filament\App\Concerns\HasDynamicNavSort;
use App\Filament\App\Resources\GraphicTemplateResource\Pages;
use App\Models\GraphicTemplate;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Actions;
use Filament\Tables;
use Filament\Tables\Table;

class GraphicTemplateResource extends Resource
{
    use ChecksRolePermissions, HasDynamicNavSort;
    protected static ?string $model = GraphicTemplate::class;

    protected static string|\BackedEnum|null $navigationIcon       = 'heroicon-o-photo';
    protected static ?string $navigationLabel      = 'Template-uri grafice';
    protected static ?string $modelLabel           = 'Template grafic';
    protected static ?string $pluralModelLabel     = 'Template-uri grafice';
    protected static string|\UnitEnum|null $navigationGroup      = 'Social Media';
    protected static ?int    $navigationSort        = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Forms\Components\Section::make('Informații generale')
                ->columnSpanFull()
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Nume template')
                        ->required()
                        ->maxLength(255),
                    Forms\Components\TextInput::make('slug')
                        ->label('Slug (identificator unic)')
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true),
                    Forms\Components\Select::make('layout')
                        ->label('Layout')
                        ->options([
                            'product' => 'Product (imagine produs dreapta)',
                            'brand'   => 'Brand (logo brand mare dreapta)',
                        ])
                        ->required()
                        ->default('product'),
                    Forms\Components\Toggle::make('is_active')
                        ->label('Activ')
                        ->default(true)
                        ->columnSpanFull(),
                ]),

            Forms\Components\Section::make('Culori & Identitate')
                ->columnSpanFull()
                ->columns(2)
                ->schema([
                    Forms\Components\ColorPicker::make('config.primary_color')
                        ->label('Culoare principală (roșu brand)')
                        ->required()
                        ->default('#a52a3f'),
                ]),

            Forms\Components\Section::make('Bara de jos')
                ->columnSpanFull()
                ->description('Textul afișat în bara colorată din partea de jos a imaginii')
                ->columns(1)
                ->schema([
                    Forms\Components\TextInput::make('config.bottom_text')
                        ->label('Text principal (uppercase automat)')
                        ->maxLength(120)
                        ->default('ASIGURĂM TRANSPORT ȘI DESCĂRCARE CU MACARA'),
                    Forms\Components\TextInput::make('config.bottom_subtext')
                        ->label('Text secundar (adresă / contact)')
                        ->maxLength(200)
                        ->default('Sântandrei, Nr. 311, vis-a-vis de Primărie  |  www.malinco.ro  |  0359 444 999'),
                    Forms\Components\Grid::make(2)->schema([
                        Forms\Components\Toggle::make('config.show_truck')
                            ->label('Afișează iconița camion')
                            ->default(true),
                        Forms\Components\Toggle::make('config.show_rainbow_bar')
                            ->label('Afișează bara curcubeu (jos)')
                            ->default(true),
                    ]),
                ]),

            Forms\Components\Section::make('Buton CTA')
                ->columnSpanFull()
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('config.cta_text')
                        ->label('Text buton CTA')
                        ->maxLength(60)
                        ->default('malinco.ro  →'),
                ]),

            Forms\Components\Section::make('Proporții & Fonturi')
                ->columnSpanFull()
                ->description('Valorile sunt proporții relative față de lățimea imaginii (1080px = 1.0)')
                ->columns(3)
                ->collapsed()
                ->schema([
                    Forms\Components\TextInput::make('config.logo_scale')
                        ->label('Scală logo Malinco')
                        ->numeric()
                        ->step(0.01)
                        ->minValue(0.15)
                        ->maxValue(0.60)
                        ->default(0.36)
                        ->helperText('0.36 = 36% din lățimea imaginii'),
                    Forms\Components\TextInput::make('config.title_size_pct')
                        ->label('Mărime font titlu')
                        ->numeric()
                        ->step(0.001)
                        ->minValue(0.04)
                        ->maxValue(0.12)
                        ->default(0.075)
                        ->helperText('0.075 = 7.5% din lățime (~81px)'),
                    Forms\Components\TextInput::make('config.subtitle_size_pct')
                        ->label('Mărime font subtitlu')
                        ->numeric()
                        ->step(0.001)
                        ->minValue(0.015)
                        ->maxValue(0.06)
                        ->default(0.029)
                        ->helperText('0.029 = 2.9% din lățime (~31px)'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nume')
                    ->searchable(),
                Tables\Columns\TextColumn::make('slug')
                    ->label('Slug')
                    ->badge()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('layout')
                    ->label('Layout')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'brand'   => 'warning',
                        'product' => 'info',
                        default   => 'gray',
                    }),
                Tables\Columns\ImageColumn::make('preview_image')
                    ->label('Preview')
                    ->disk('public')
                    ->square()
                    ->size(80),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Activ')
                    ->boolean(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Actualizat')
                    ->since(),
            ])
            ->recordActions([
                Actions\Action::make('visual_editor')
                    ->label('Editor vizual')
                    ->icon('heroicon-o-paint-brush')
                    ->color('primary')
                    ->url(fn ($record) => route('template-editor.show', $record))
                    ->openUrlInNewTab(),
                Actions\EditAction::make()->label('Setări'),
                Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListGraphicTemplates::route('/'),
            'create' => Pages\CreateGraphicTemplate::route('/create'),
            'edit'   => Pages\EditGraphicTemplate::route('/{record}/edit'),
        ];
    }
}
