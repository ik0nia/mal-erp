<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\DiscountPolicyResource\Pages;
use App\Models\DiscountPolicy;
use App\Models\Supplier;
use App\Models\User;
use App\Models\WooCategory;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Actions;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class DiscountPolicyResource extends Resource
{
    protected static ?string $model = DiscountPolicy::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected static string|\UnitEnum|null $navigationGroup = 'Vânzări';

    protected static ?string $navigationLabel = 'Politici discount';

    protected static ?string $modelLabel = 'Politică discount';

    protected static ?string $pluralModelLabel = 'Politici discount';

    protected static ?int $navigationSort = 11;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && ($user->isAdmin() || $user->isSuperAdmin());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make('Cui se aplică')
                ->columns(2)
                ->schema([
                    Select::make('subject_type')
                        ->label('Tip subiect')
                        ->options(DiscountPolicy::subjectOptions())
                        ->default(DiscountPolicy::SUBJECT_ROLE)
                        ->required()
                        ->native(false)
                        ->live(),
                    Select::make('role')
                        ->label('Rol')
                        ->options(User::roleOptions())
                        ->native(false)
                        ->searchable()
                        ->required(fn (Get $get): bool => $get('subject_type') === DiscountPolicy::SUBJECT_ROLE)
                        ->visible(fn (Get $get): bool => $get('subject_type') === DiscountPolicy::SUBJECT_ROLE),
                    Select::make('user_id')
                        ->label('Utilizator (excepție)')
                        ->relationship('user', 'name')
                        ->searchable()
                        ->preload()
                        ->native(false)
                        ->required(fn (Get $get): bool => $get('subject_type') === DiscountPolicy::SUBJECT_USER)
                        ->visible(fn (Get $get): bool => $get('subject_type') === DiscountPolicy::SUBJECT_USER),
                ]),
            Section::make('Pe ce produse')
                ->columns(2)
                ->schema([
                    Select::make('scope_type')
                        ->label('Scop')
                        ->options(DiscountPolicy::scopeOptions())
                        ->default(DiscountPolicy::SCOPE_ALL)
                        ->required()
                        ->native(false)
                        ->live(),
                    Select::make('woo_category_id')
                        ->label('Categorie')
                        ->options(fn (): array => WooCategory::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()
                        ->native(false)
                        ->required(fn (Get $get): bool => $get('scope_type') === DiscountPolicy::SCOPE_CATEGORY)
                        ->visible(fn (Get $get): bool => $get('scope_type') === DiscountPolicy::SCOPE_CATEGORY),
                    Select::make('supplier_id')
                        ->label('Furnizor')
                        ->options(fn (): array => Supplier::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()
                        ->native(false)
                        ->required(fn (Get $get): bool => $get('scope_type') === DiscountPolicy::SCOPE_SUPPLIER)
                        ->visible(fn (Get $get): bool => $get('scope_type') === DiscountPolicy::SCOPE_SUPPLIER),
                ]),
            Section::make('Plafoane')
                ->columns(2)
                ->schema([
                    TextInput::make('max_discount_percent')
                        ->label('Discount maxim fără aprobare')
                        ->helperText('Până la acest procent, vânzătorul acordă discount liber.')
                        ->numeric()->minValue(0)->maxValue(100)->step(0.01)
                        ->suffix('%')->default(0)->required(),
                    TextInput::make('approval_discount_percent')
                        ->label('Discount maxim cu aprobare')
                        ->helperText('Între plafonul de mai sus și acesta, oferta intră la aprobare manager. Gol = fără treaptă de aprobare.')
                        ->numeric()->minValue(0)->maxValue(100)->step(0.01)
                        ->suffix('%'),
                    TextInput::make('label')
                        ->label('Etichetă (opțional)')
                        ->maxLength(255)
                        ->columnSpanFull(),
                    Toggle::make('is_active')
                        ->label('Activă')
                        ->default(true),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('subject')
                    ->label('Subiect')
                    ->state(fn (DiscountPolicy $r): string => $r->subject_type === DiscountPolicy::SUBJECT_USER
                        ? ('👤 ' . ($r->user?->name ?? '—'))
                        : (User::roleOptions()[$r->role] ?? $r->role ?? '—')),
                Tables\Columns\TextColumn::make('scope')
                    ->label('Se aplică pe')
                    ->state(fn (DiscountPolicy $r): string => match ($r->scope_type) {
                        DiscountPolicy::SCOPE_CATEGORY => 'Categorie: ' . ($r->category?->name ?? '—'),
                        DiscountPolicy::SCOPE_SUPPLIER => 'Furnizor: ' . ($r->supplier?->name ?? '—'),
                        default => 'Toate produsele',
                    })
                    ->wrap(),
                Tables\Columns\TextColumn::make('max_discount_percent')
                    ->label('Max fără aprobare')
                    ->formatStateUsing(fn ($s): string => number_format((float) $s, 2) . '%')
                    ->alignRight(),
                Tables\Columns\TextColumn::make('approval_discount_percent')
                    ->label('Max cu aprobare')
                    ->formatStateUsing(fn ($s): string => $s !== null ? number_format((float) $s, 2) . '%' : '—')
                    ->alignRight(),
                Tables\Columns\ToggleColumn::make('is_active')
                    ->label('Activă'),
                Tables\Columns\TextColumn::make('label')
                    ->label('Etichetă')
                    ->toggleable()
                    ->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('role')
                    ->label('Rol')
                    ->options(User::roleOptions()),
                Tables\Filters\SelectFilter::make('scope_type')
                    ->label('Scop')
                    ->options(DiscountPolicy::scopeOptions()),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                Actions\EditAction::make(),
                Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['user', 'category', 'supplier']);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListDiscountPolicies::route('/'),
            'create' => Pages\CreateDiscountPolicy::route('/create'),
            'edit'   => Pages\EditDiscountPolicy::route('/{record}/edit'),
        ];
    }
}
