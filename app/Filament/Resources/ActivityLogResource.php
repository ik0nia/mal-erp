<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ActivityLogResource\Pages;
use App\Models\User;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Spatie\Activitylog\Models\Activity;

class ActivityLogResource extends Resource
{
    protected static ?string $model = Activity::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-finger-print';

    protected static string|\UnitEnum|null $navigationGroup = 'Conformitate';

    protected static ?string $navigationLabel = 'Jurnal de audit';

    protected static ?string $modelLabel = 'înregistrare audit';

    protected static ?string $pluralModelLabel = 'Jurnal de audit';

    protected static ?int $navigationSort = 20;

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

    private static function eventLabel(?string $event): string
    {
        return match ($event) {
            'created' => 'Creare',
            'updated' => 'Modificare',
            'deleted' => 'Ștergere',
            'restored' => 'Restaurare',
            default => $event ?: '—',
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Data')
                    ->dateTime('d.m.Y H:i:s')
                    ->sortable(),
                Tables\Columns\TextColumn::make('causer')
                    ->label('Utilizator')
                    ->getStateUsing(fn (Activity $record): string => $record->causer?->name
                        ?? ($record->causer_id ? "#{$record->causer_id}" : 'Sistem'))
                    ->searchable(query: fn ($query, $search) => $query->whereHasMorph(
                        'causer', [User::class], fn ($q) => $q->where('name', 'like', "%{$search}%")
                    ))
                    ->description(fn (Activity $record): ?string => $record->causer?->email),
                Tables\Columns\TextColumn::make('event')
                    ->label('Acțiune')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => static::eventLabel($state))
                    ->color(fn (?string $state): string => match ($state) {
                        'created' => 'success',
                        'updated' => 'warning',
                        'deleted' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('subject')
                    ->label('Entitate')
                    ->getStateUsing(fn (Activity $record): string => $record->subject_type
                        ? class_basename($record->subject_type).' '.($record->properties['subject_label']
                            ?? ($record->subject_id ? "#{$record->subject_id}" : ''))
                        : '—'),
                Tables\Columns\TextColumn::make('description')
                    ->label('Descriere')
                    ->wrap()
                    ->limit(60),
                Tables\Columns\TextColumn::make('log_name')
                    ->label('Jurnal')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('event')
                    ->label('Acțiune')
                    ->options([
                        'created' => 'Creare',
                        'updated' => 'Modificare',
                        'deleted' => 'Ștergere',
                        'restored' => 'Restaurare',
                    ]),
                Tables\Filters\SelectFilter::make('log_name')
                    ->label('Jurnal')
                    ->options(fn (): array => Activity::query()
                        ->distinct()
                        ->pluck('log_name', 'log_name')
                        ->filter()
                        ->toArray()),
                Tables\Filters\SelectFilter::make('subject_type')
                    ->label('Entitate')
                    ->options(fn (): array => Activity::query()
                        ->distinct()
                        ->pluck('subject_type', 'subject_type')
                        ->filter()
                        ->mapWithKeys(fn ($t) => [$t => class_basename($t)])
                        ->toArray()),
                Tables\Filters\Filter::make('created_at')
                    ->schema([
                        \Filament\Forms\Components\DatePicker::make('from')->label('De la'),
                        \Filament\Forms\Components\DatePicker::make('until')->label('Până la'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                            ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d));
                    }),
            ])
            ->deferFilters(false)
            ->recordActions([
                \Filament\Actions\Action::make('details')
                    ->label('Detalii')
                    ->icon('heroicon-o-eye')
                    ->modalSubmitAction(false)
                    ->modalHeading(fn (Activity $record): string => 'Audit #'.$record->id)
                    ->modalContent(function (Activity $record): HtmlString {
                        $payload = [
                            'data' => $record->created_at?->toDateTimeString(),
                            'utilizator' => $record->causer?->name ?? ($record->causer_id ? "#{$record->causer_id}" : 'Sistem'),
                            'email' => $record->causer?->email,
                            'acțiune' => static::eventLabel($record->event),
                            'entitate' => $record->subject_type
                                ? class_basename($record->subject_type).' '.($record->properties['subject_label'] ?? '#'.$record->subject_id)
                                : null,
                            'descriere' => $record->description,
                            'jurnal' => $record->log_name,
                            'modificări' => $record->properties,
                        ];

                        return new HtmlString('<pre style="white-space:pre-wrap;word-break:break-word;font-size:12px;">'
                            .e(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)).'</pre>');
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListActivityLogs::route('/'),
        ];
    }
}
