<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SyncRunResource\Pages;
use App\Models\IntegrationConnection;
use App\Models\SyncRun;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

class SyncRunResource extends Resource
{
    protected static ?string $model = SyncRun::class;

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationGroup = 'Integrări';

    protected static ?string $navigationLabel = 'Sync Runs';

    protected static ?string $modelLabel = 'Sync run';

    protected static ?string $pluralModelLabel = 'Sync runs';

    protected static function currentUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::currentUser()?->isAdmin() ?? false;
    }

    public static function canViewAny(): bool
    {
        return static::currentUser()?->isAdmin() ?? false;
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

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('provider')
                    ->label('Provider')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => IntegrationConnection::providerOptions()[$state] ?? $state),
                Tables\Columns\TextColumn::make('connection.name')
                    ->label('Conexiune')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('location.name')
                    ->label('Magazin')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('type')
                    ->label('Tip')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        SyncRun::STATUS_QUEUED => 'info',
                        SyncRun::STATUS_SUCCESS => 'success',
                        SyncRun::STATUS_FAILED => 'danger',
                        SyncRun::STATUS_CANCELLED => 'gray',
                        default => 'warning',
                    }),
                Tables\Columns\TextColumn::make('stats.created')
                    ->label('Created')
                    ->getStateUsing(fn (SyncRun $record): int => (int) ($record->stats['created'] ?? 0)),
                Tables\Columns\TextColumn::make('stats.updated')
                    ->label('Updated')
                    ->getStateUsing(fn (SyncRun $record): int => (int) ($record->stats['updated'] ?? 0)),
                Tables\Columns\TextColumn::make('stats.pages')
                    ->label('Pages')
                    ->getStateUsing(fn (SyncRun $record): int => (int) ($record->stats['pages'] ?? 0)),
                Tables\Columns\TextColumn::make('stats.missing_products')
                    ->label('Missing SKU')
                    ->getStateUsing(fn (SyncRun $record): int => (int) ($record->stats['missing_products'] ?? 0))
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('stats.name_mismatches')
                    ->label('Name mismatch')
                    ->getStateUsing(fn (SyncRun $record): int => (int) ($record->stats['name_mismatches'] ?? 0))
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('stats.created_placeholders')
                    ->label('Placeholder ERP')
                    ->getStateUsing(fn (SyncRun $record): int => (int) ($record->stats['created_placeholders'] ?? 0))
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('stats.site_price_updates')
                    ->label('Price push OK')
                    ->getStateUsing(fn (SyncRun $record): int => (int) ($record->stats['site_price_updates'] ?? 0))
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('stats.site_price_update_failures')
                    ->label('Price push failed')
                    ->getStateUsing(fn (SyncRun $record): int => (int) ($record->stats['site_price_update_failures'] ?? 0))
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('started_at')
                    ->label('Started')
                    ->dateTime('d.m.Y H:i:s')
                    ->sortable(),
                Tables\Columns\TextColumn::make('finished_at')
                    ->label('Finished')
                    ->dateTime('d.m.Y H:i:s')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('provider')
                    ->label('Provider')
                    ->options(IntegrationConnection::providerOptions()),
                Tables\Filters\SelectFilter::make('connection_id')
                    ->label('Conexiune')
                    ->options(fn (): array => IntegrationConnection::query()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all()),
                Tables\Filters\SelectFilter::make('type')
                    ->label('Tip')
                    ->options([
                        SyncRun::TYPE_CATEGORIES => 'categories',
                        SyncRun::TYPE_PRODUCTS => 'products',
                        SyncRun::TYPE_WINMENTOR_STOCK => 'winmentor_stock',
                    ]),
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        SyncRun::STATUS_QUEUED => 'queued',
                        SyncRun::STATUS_RUNNING => 'running',
                        SyncRun::STATUS_SUCCESS => 'success',
                        SyncRun::STATUS_FAILED => 'failed',
                        SyncRun::STATUS_CANCELLED => 'cancelled',
                    ]),
            ])
            ->actions([
                Tables\Actions\Action::make('stop')
                    ->label('Stop')
                    ->icon('heroicon-o-stop')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (SyncRun $record): bool => in_array($record->status, [SyncRun::STATUS_QUEUED, SyncRun::STATUS_RUNNING], true))
                    ->action(function (SyncRun $record): void {
                        $record->refresh();

                        if (! in_array($record->status, [SyncRun::STATUS_QUEUED, SyncRun::STATUS_RUNNING], true)) {
                            Notification::make()
                                ->warning()
                                ->title('Importul nu mai este în curs')
                                ->send();

                            return;
                        }

                        $errors = is_array($record->errors) ? $record->errors : [];
                        $errors[] = [
                            'message' => 'Oprit manual din platformă.',
                            'cancelled_at' => now()->toIso8601String(),
                            'cancelled_by' => auth()->user()?->email ?? auth()->id(),
                        ];

                        if (count($errors) > 200) {
                            $errors = array_slice($errors, -200);
                        }

                        $record->update([
                            'status' => SyncRun::STATUS_CANCELLED,
                            'finished_at' => now(),
                            'errors' => $errors,
                        ]);

                        Notification::make()
                            ->success()
                            ->title('Import oprit')
                            ->body('Execuția va fi întreruptă în siguranță la următorul checkpoint.')
                            ->send();
                    }),
                Tables\Actions\Action::make('details')
                    ->label('View details')
                    ->icon('heroicon-o-eye')
                    ->modalSubmitAction(false)
                    ->modalHeading(fn (SyncRun $record): string => "Sync run #{$record->id}")
                    ->modalContent(function (SyncRun $record): HtmlString {
                        $payload = [
                            'stats' => $record->stats,
                            'errors' => $record->errors,
                        ];

                        return new HtmlString(
                            '<pre style="white-space: pre-wrap;">'.e(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)).'</pre>'
                        );
                    }),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSyncRuns::route('/'),
        ];
    }
}
