<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PageVisitResource\Pages;
use App\Models\User;
use App\Models\UserPageVisit;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PageVisitResource extends Resource
{
    protected static ?string $model = UserPageVisit::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-map';

    protected static string|\UnitEnum|null $navigationGroup = 'Conformitate';

    protected static ?string $navigationLabel = 'Jurnal de navigare';

    protected static ?string $modelLabel = 'accesare pagină';

    protected static ?string $pluralModelLabel = 'Jurnal de navigare';

    protected static ?int $navigationSort = 21;

    protected static function currentUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::currentUser()?->isSuperAdmin() ?? false;
    }

    public static function canViewAny(): bool
    {
        return static::currentUser()?->isSuperAdmin() ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('user:id,name,email');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('visited_at')
                    ->label('Când')
                    ->dateTime('d.m.Y H:i:s')
                    ->sortable(),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Utilizator')
                    ->description(fn (UserPageVisit $r): ?string => $r->user?->email)
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('label')
                    ->label('Pagină')
                    ->getStateUsing(fn (UserPageVisit $r): string => $r->label)
                    ->description(fn (UserPageVisit $r): string => '/'.$r->path)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('path', 'like', "%{$search}%"))
                    ->wrap(),
                Tables\Columns\TextColumn::make('route_name')
                    ->label('Rută')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('visited_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('user_id')
                    ->label('Utilizator')
                    ->searchable()
                    ->options(fn (): array => User::orderBy('name')->pluck('name', 'id')->toArray()),
                Tables\Filters\Filter::make('visited_at')
                    ->label('Interval')
                    ->schema([
                        \Filament\Forms\Components\DatePicker::make('from')->label('De la'),
                        \Filament\Forms\Components\DatePicker::make('until')->label('Până la'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, string $d): Builder => $q->whereDate('visited_at', '>=', $d))
                            ->when($data['until'] ?? null, fn (Builder $q, string $d): Builder => $q->whereDate('visited_at', '<=', $d));
                    }),
            ])
            ->deferFilters(false)
            ->searchPlaceholder('Caută pagină (cale)...')
            ->poll('60s')
            ->recordActions([])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPageVisits::route('/'),
        ];
    }
}
