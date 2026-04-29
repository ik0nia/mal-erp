<?php

namespace App\Filament\App\Pages;

use App\Models\PurchaseOrder;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Filament\Tables\Filters\SelectFilter;

class WinmentorSyncLogPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon  = 'heroicon-o-clipboard-document-list';
    protected static ?string $navigationLabel = 'Log WinMentor';
    protected static ?string $title           = 'Log sincronizare WinMentor';
    protected static string|\UnitEnum|null $navigationGroup = 'WinMentor';
    protected static ?int    $navigationSort  = 20;
    protected string $view = 'filament.app.pages.winmentor-sync-log';

    public static function canAccess(): bool
    {
        $user = auth()->user();
        if (! $user instanceof \App\Models\User) return false;
        return $user->isSuperAdmin() || $user->isAdmin() || in_array($user->role, [
            \App\Models\User::ROLE_MANAGER,
            \App\Models\User::ROLE_MANAGER_ACHIZITII,
        ], true);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                PurchaseOrder::query()
                    ->where('status', PurchaseOrder::STATUS_RECEIVED)
                    ->with(['supplier'])
                    ->latest('received_at')
            )
            ->columns([
                Tables\Columns\TextColumn::make('number')
                    ->label('Nr. comandă')
                    ->searchable()
                    ->weight('bold')
                    ->url(fn (PurchaseOrder $record) => route('filament.app.resources.purchase-orders.view', $record)),

                Tables\Columns\TextColumn::make('supplier.name')
                    ->label('Furnizor')
                    ->searchable(),

                Tables\Columns\TextColumn::make('received_at')
                    ->label('Data recepție')
                    ->date('d.m.Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('winmentor_sync_status')
                    ->label('Status WinMentor')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match($state) {
                        PurchaseOrder::WINMENTOR_PENDING => 'În așteptare',
                        PurchaseOrder::WINMENTOR_SYNCED  => 'Importat',
                        PurchaseOrder::WINMENTOR_FAILED  => 'Eroare',
                        null                             => 'Nesincronizat',
                        default                          => $state,
                    })
                    ->color(fn ($state) => match($state) {
                        PurchaseOrder::WINMENTOR_PENDING => 'warning',
                        PurchaseOrder::WINMENTOR_SYNCED  => 'success',
                        PurchaseOrder::WINMENTOR_FAILED  => 'danger',
                        default                          => 'gray',
                    }),

                Tables\Columns\TextColumn::make('winmentor_order_nr')
                    ->label('Nr. document WinMentor')
                    ->placeholder('—')
                    ->badge()
                    ->color('success'),

                Tables\Columns\TextColumn::make('winmentor_sync_error')
                    ->label('Eroare')
                    ->wrap()
                    ->color('danger')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: false),

                Tables\Columns\TextColumn::make('invoice_number')
                    ->label('Factură')
                    ->formatStateUsing(fn ($state, PurchaseOrder $record) =>
                        collect([$record->invoice_series, $state])->filter()->implode(' ')
                    )
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('winmentor_sync_status')
                    ->label('Status WinMentor')
                    ->options([
                        PurchaseOrder::WINMENTOR_PENDING => 'În așteptare',
                        PurchaseOrder::WINMENTOR_SYNCED  => 'Importat cu succes',
                        PurchaseOrder::WINMENTOR_FAILED  => 'Eroare',
                    ])
                    ->placeholder('Toate'),
            ])
            ->defaultSort('received_at', 'desc')
            ->emptyStateHeading('Nicio recepție')
            ->emptyStateIcon('heroicon-o-inbox');
    }
}
