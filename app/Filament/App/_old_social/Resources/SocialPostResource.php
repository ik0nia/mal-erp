<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Concerns\ChecksRolePermissions;
use App\Filament\App\Concerns\HasDynamicNavSort;
use App\Filament\App\Resources\SocialPostResource\Pages;
use App\Models\GraphicTemplate;
use App\Models\SocialPost;
use App\Models\WooProduct;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\ImageEntry;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Actions;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SocialPostResource extends Resource
{
    use ChecksRolePermissions, HasDynamicNavSort;

    protected static ?string $model           = SocialPost::class;
    protected static string|\BackedEnum|null $navigationIcon  = 'heroicon-o-share';
    protected static string|\UnitEnum|null $navigationGroup = 'Social Media';
    protected static ?string $navigationLabel = 'Postări';
    protected static ?string $modelLabel      = 'Postare';
    protected static ?string $pluralModelLabel = 'Postări Social Media';
    protected static ?int    $navigationSort  = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Select::make('social_account_id')
                ->label('Cont Facebook')
                ->relationship('account', 'name')
                ->required()
                ->default(fn () => \App\Models\SocialAccount::where('is_active', true)->first()?->id),

            Select::make('brief_type')
                ->label('Tip postare')
                ->options(SocialPost::typeOptions())
                ->required()
                ->live()
                ->default(SocialPost::TYPE_PRODUCT),

            Select::make('woo_product_id')
                ->label('Produs (opțional)')
                ->options(fn () => WooProduct::whereNotNull('woo_id')
                    ->orderBy('name')
                    ->limit(500)
                    ->pluck('name', 'id')
                    ->all())
                ->searchable()
                ->nullable()
                ->visible(fn ($get) => $get('brief_type') === SocialPost::TYPE_PRODUCT),

            Select::make('brand_id')
                ->label('Brand')
                ->relationship('brand', 'name')
                ->searchable()
                ->preload()
                ->nullable()
                ->visible(fn ($get) => $get('brief_type') === SocialPost::TYPE_BRAND),

            Select::make('template')
                ->label('Template grafic')
                ->placeholder('— Auto (după tipul postării) —')
                ->options(fn () => GraphicTemplate::where('is_active', true)
                    ->orderBy('name')
                    ->pluck('name', 'slug')
                    ->all())
                ->nullable()
                ->helperText('Dacă nu alegi, se selectează automat template-ul potrivit tipului de postare.'),

            Textarea::make('brief_direction')
                ->label('Direcție / Brief')
                ->placeholder('Ex: Promovează cărămizile Wienerberger pentru construcții rezidențiale. Accent pe durabilitate și izolație termică.')
                ->rows(4)
                ->required(),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make('Preview postare')
                ->columns(2)
                ->schema([
                    ImageEntry::make('image_path')
                        ->label('Imagine generată')
                        ->disk('public')
                        ->height(400)
                        ->extraImgAttributes(['class' => 'rounded-xl object-cover w-full'])
                        ->columnSpan(1),

                    Section::make()
                        ->columnSpan(1)
                        ->schema([
                            TextEntry::make('status')
                                ->label('Status')
                                ->badge()
                                ->formatStateUsing(fn ($state) => SocialPost::statusOptions()[$state] ?? $state)
                                ->color(fn ($state) => SocialPost::statusColors()[$state] ?? 'gray'),

                            TextEntry::make('brief_type')
                                ->label('Tip')
                                ->badge()
                                ->formatStateUsing(fn ($state) => SocialPost::typeOptions()[$state] ?? $state),

                            TextEntry::make('template')
                                ->label('Template grafic')
                                ->formatStateUsing(function ($state, $record) {
                                    if (filled($state) && $state !== 'clasic') {
                                        $tpl = \App\Models\GraphicTemplate::where('slug', $state)->first();
                                        return $tpl?->name ?? $state;
                                    }
                                    // Afișăm ce template s-ar folosi prin auto-selecție
                                    $tpl = \App\Models\GraphicTemplate::where('layout', $record->brief_type)
                                        ->where('is_active', true)->first()
                                        ?? \App\Models\GraphicTemplate::default();
                                    return ($tpl?->name ?? 'N/A') . ' (auto)';
                                }),

                            TextEntry::make('account.name')
                                ->label('Cont'),

                            TextEntry::make('product.name')
                                ->label('Produs')
                                ->placeholder('—'),

                            TextEntry::make('brand.name')
                                ->label('Brand')
                                ->placeholder('—'),

                            TextEntry::make('scheduled_at')
                                ->label('Programată')
                                ->dateTime('d.m.Y H:i')
                                ->placeholder('—'),

                            TextEntry::make('published_at')
                                ->label('Publicată')
                                ->dateTime('d.m.Y H:i')
                                ->placeholder('—'),

                            TextEntry::make('platform_url')
                                ->label('Link postare')
                                ->url(fn ($record) => $record->platform_url)
                                ->openUrlInNewTab()
                                ->placeholder('—'),
                        ]),
                ]),

            Section::make('Conținut generat')
                ->schema([
                    TextEntry::make('caption')
                        ->label('Caption')
                        ->placeholder('Se generează...')
                        ->html()
                        ->formatStateUsing(fn ($state) => $state ? nl2br(e($state)) : null)
                        ->columnSpanFull(),
                ]),

            Section::make('Texte grafică')
                ->columns(3)
                ->collapsed()
                ->schema([
                    TextEntry::make('graphic_label')
                        ->label('Label (eyebrow)')
                        ->placeholder('—'),
                    TextEntry::make('graphic_title')
                        ->label('Titlu grafică')
                        ->placeholder('—'),
                    TextEntry::make('graphic_subtitle')
                        ->label('Subtitlu grafică')
                        ->placeholder('—'),
                ]),

            Section::make('Brief')
                ->collapsed()
                ->schema([
                    TextEntry::make('brief_direction')
                        ->label('Direcție')
                        ->columnSpanFull(),

                    TextEntry::make('image_prompt')
                        ->label('Prompt imagine (Gemini)')
                        ->placeholder('—')
                        ->columnSpanFull(),
                ]),

            Section::make('Eroare')
                ->visible(fn ($record) => filled($record->error_message))
                ->schema([
                    TextEntry::make('error_message')
                        ->label('Detalii eroare')
                        ->color('danger')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\ImageColumn::make('image_path')
                    ->label('')
                    ->disk('public')
                    ->width(60)
                    ->height(60)
                    ->defaultImageUrl(fn () => null)
                    ->extraImgAttributes(['class' => 'rounded object-cover']),

                Tables\Columns\TextColumn::make('brief_type')
                    ->label('Tip')
                    ->badge()
                    ->formatStateUsing(fn ($state) => SocialPost::typeOptions()[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'product' => 'info',
                        'brand'   => 'success',
                        'promo'   => 'warning',
                        default   => 'gray',
                    }),

                Tables\Columns\TextColumn::make('brief_direction')
                    ->label('Brief')
                    ->limit(60)
                    ->tooltip(fn ($record) => $record->brief_direction),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => SocialPost::statusOptions()[$state] ?? $state)
                    ->color(fn ($state) => SocialPost::statusColors()[$state] ?? 'gray'),

                Tables\Columns\TextColumn::make('scheduled_at')
                    ->label('Programată')
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('published_at')
                    ->label('Publicată')
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Creat')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(SocialPost::statusOptions()),

                Tables\Filters\SelectFilter::make('brief_type')
                    ->label('Tip')
                    ->options(SocialPost::typeOptions()),
            ])
            ->deferFilters(false)
            ->recordActions([
                Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListSocialPosts::route('/'),
            'create' => Pages\CreateSocialPost::route('/create'),
            'view'   => Pages\ViewSocialPost::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['account', 'product', 'brand']);
    }
}
