<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BreachIncidentResource\Pages;
use App\Models\BreachIncident;
use App\Models\User;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class BreachIncidentResource extends Resource
{
    protected static ?string $model = BreachIncident::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shield-exclamation';

    protected static string|\UnitEnum|null $navigationGroup = 'Conformitate';

    protected static ?string $navigationLabel = 'Registru breșe (GDPR)';

    protected static ?string $modelLabel = 'breșă de securitate';

    protected static ?string $pluralModelLabel = 'Registru breșe (GDPR)';

    protected static ?int $navigationSort = 40;

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

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Forms\Components\Select::make('type')
                ->label('Tip')->options(BreachIncident::TYPES)->default('data_breach')->required()->native(false)
                ->helperText('„Breșă de date personale" activează termenul de 72h pentru notificarea ANSPDCP. „Alertă de securitate" = eveniment fără compromitere de date (ex. tentativă brute-force) — fără notificare externă.')
                ->columnSpanFull(),
            Forms\Components\TextInput::make('title')
                ->label('Titlu')->required()->maxLength(255)->columnSpanFull(),
            Forms\Components\Select::make('severity')
                ->label('Severitate')->options(BreachIncident::SEVERITIES)->default('medium')->required()->native(false),
            Forms\Components\Select::make('status')
                ->label('Status')->options(BreachIncident::STATUSES)->default('open')->required()->native(false),
            Forms\Components\DateTimePicker::make('detected_at')
                ->label('Detectată la')->default(now())->required()->seconds(false)
                ->helperText('Termenul de notificare ANSPDCP (72h) se calculează automat de la acest moment.'),
            Forms\Components\TextInput::make('affected_scope')
                ->label('Date / sisteme afectate')->maxLength(255),
            Forms\Components\TextInput::make('affected_count')
                ->label('Nr. persoane vizate (estimat)')->numeric()->minValue(0),
            Forms\Components\Textarea::make('description')
                ->label('Descriere')->rows(4)->columnSpanFull(),
            Forms\Components\DateTimePicker::make('authority_notified_at')
                ->label('ANSPDCP notificat la')->seconds(false),
            Forms\Components\DateTimePicker::make('subjects_notified_at')
                ->label('Persoane vizate notificate la')->seconds(false),
            Forms\Components\DateTimePicker::make('resolved_at')
                ->label('Rezolvată la')->seconds(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('detected_at')
                    ->label('Detectată')->dateTime('d.m.Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('title')
                    ->label('Titlu')->searchable()->wrap()->limit(50),
                Tables\Columns\TextColumn::make('type')
                    ->label('Tip')->badge()
                    ->formatStateUsing(fn (string $s): string => BreachIncident::TYPES[$s] ?? $s)
                    ->color(fn (string $s): string => $s === 'security_alert' ? 'gray' : 'info'),
                Tables\Columns\TextColumn::make('severity')
                    ->label('Severitate')->badge()
                    ->formatStateUsing(fn (string $s): string => BreachIncident::SEVERITIES[$s] ?? $s)
                    ->color(fn (string $s): string => match ($s) {
                        'critical', 'high' => 'danger',
                        'medium' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')->badge()
                    ->formatStateUsing(fn (string $s): string => BreachIncident::STATUSES[$s] ?? $s)
                    ->color(fn (string $s): string => match ($s) {
                        'closed' => 'success',
                        'notified' => 'info',
                        'open' => 'danger',
                        default => 'warning',
                    }),
                Tables\Columns\TextColumn::make('authority_notify_due_at')
                    ->label('Termen ANSPDCP (72h)')
                    ->getStateUsing(fn (BreachIncident $record): ?string => $record->isSecurityAlert()
                        ? null
                        : $record->authority_notify_due_at?->format('d.m.Y H:i'))
                    ->placeholder('— nu se aplică')
                    ->color(fn (BreachIncident $record): string => $record->isAuthorityNotificationOverdue() ? 'danger' : 'gray')
                    ->description(fn (BreachIncident $record): ?string => $record->isAuthorityNotificationOverdue() ? '⚠ DEPĂȘIT' : null),
                Tables\Columns\TextColumn::make('reportedBy.name')
                    ->label('Raportat de')->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('severity')->label('Severitate')->options(BreachIncident::SEVERITIES),
                Tables\Filters\SelectFilter::make('status')->label('Status')->options(BreachIncident::STATUSES),
            ])
            ->recordActions([
                \Filament\Actions\EditAction::make(),
            ])
            ->defaultSort('detected_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBreachIncidents::route('/'),
            'create' => Pages\CreateBreachIncident::route('/create'),
            'edit' => Pages\EditBreachIncident::route('/{record}/edit'),
        ];
    }
}
