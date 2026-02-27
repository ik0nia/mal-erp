<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\EanAssociationRequestResource\Pages;
use App\Models\EanAssociationRequest;
use Filament\Forms;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class EanAssociationRequestResource extends Resource
{
    protected static ?string $model = EanAssociationRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-qr-code';
    protected static ?string $navigationGroup = 'Administrare magazin';
    protected static ?string $navigationLabel = 'Cereri asociere EAN';
    protected static ?string $modelLabel = 'Cerere asociere EAN';
    protected static ?string $pluralModelLabel = 'Cereri asociere EAN';
    protected static ?int $navigationSort = 99;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('ean')
                    ->label('EAN scanat')
                    ->searchable()
                    ->copyable()
                    ->fontFamily('mono'),

                Tables\Columns\TextColumn::make('product.name')
                    ->label('Produs propus')
                    ->searchable()
                    ->limit(40),

                Tables\Columns\TextColumn::make('product.sku')
                    ->label('SKU curent')
                    ->fontFamily('mono')
                    ->color('gray'),

                Tables\Columns\TextColumn::make('requestedBy.name')
                    ->label('Solicitat de'),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'warning' => 'pending',
                        'success' => 'approved',
                        'danger'  => 'rejected',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending'  => 'În așteptare',
                        'approved' => 'Aprobat',
                        'rejected' => 'Respins',
                        default    => $state,
                    }),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Data')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'pending'  => 'În așteptare',
                        'approved' => 'Aprobat',
                        'rejected' => 'Respins',
                    ]),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label('Aprobă')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (EanAssociationRequest $record) => $record->status === 'pending')
                    ->requiresConfirmation()
                    ->modalHeading('Marchează ca aprobat')
                    ->modalDescription(fn (EanAssociationRequest $record) => "Marchezi cererea de asociere EAN «{$record->ean}» → «{$record->product->name}» ca aprobată. Procesarea efectivă a SKU-ului se face separat.")
                    ->action(function (EanAssociationRequest $record): void {
                        $record->update([
                            'status'       => EanAssociationRequest::STATUS_APPROVED,
                            'processed_by' => Auth::id(),
                            'processed_at' => now(),
                        ]);

                        Notification::make()
                            ->title('Cerere aprobată')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('reject')
                    ->label('Respinge')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (EanAssociationRequest $record) => $record->status === 'pending')
                    ->form([
                        Forms\Components\Textarea::make('notes')
                            ->label('Motiv respingere (opțional)')
                            ->rows(3),
                    ])
                    ->action(function (EanAssociationRequest $record, array $data): void {
                        $record->update([
                            'status'       => EanAssociationRequest::STATUS_REJECTED,
                            'processed_by' => Auth::id(),
                            'processed_at' => now(),
                            'notes'        => $data['notes'] ?? null,
                        ]);

                        Notification::make()
                            ->title('Cerere respinsă')
                            ->warning()
                            ->send();
                    }),
            ])
            ->bulkActions([])
            ->emptyStateHeading('Nicio cerere de asociere EAN')
            ->emptyStateDescription('Cererile apar aici când utilizatorii scanează EAN-uri necunoscute și propun asocierea lor la un produs existent.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEanAssociationRequests::route('/'),
        ];
    }
}
