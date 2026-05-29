<?php

namespace App\Filament\App\Pages;

use App\Jobs\PushReceptionToWinmentorJob;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderReception;
use App\Services\Winmentor\PushComenziFurnizoriService;
use App\Services\Winmentor\WinmentorBridgeClient;
use Filament\Actions\Action;
use Filament\Actions\Action as TableAction;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WinmentorReceptionQueuePage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon  = 'heroicon-o-queue-list';
    protected static ?string $navigationLabel = 'Coadă WinMentor';
    protected static ?string $title           = 'Coadă sincronizare WinMentor';
    protected static string|\UnitEnum|null $navigationGroup = 'WinMentor';
    protected static ?int    $navigationSort  = 10;
    protected string $view = 'filament.app.pages.winmentor-reception-queue';

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
                PurchaseOrderReception::query()
                    ->whereIn('winmentor_sync_status', [
                        PurchaseOrderReception::WINMENTOR_PENDING,
                        PurchaseOrderReception::WINMENTOR_FAILED,
                    ])
                    ->with(['purchaseOrder.supplier', 'purchaseOrder.items', 'items'])
                    ->latest('received_at')
            )
            ->columns([
                Tables\Columns\TextColumn::make('purchaseOrder.number')
                    ->label('Nr. comandă')
                    ->searchable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('reception_number')
                    ->label('Recepție #')
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('purchaseOrder.supplier.name')
                    ->label('Furnizor')
                    ->searchable(),

                Tables\Columns\TextColumn::make('received_at')
                    ->label('Data recepție')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('items_count')
                    ->label('Produse')
                    ->counts('items')
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('winmentor_sync_status')
                    ->label('Status WinMentor')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match($state) {
                        PurchaseOrderReception::WINMENTOR_PENDING => 'În așteptare',
                        PurchaseOrderReception::WINMENTOR_FAILED  => 'Eroare',
                        default                                   => $state,
                    })
                    ->color(fn ($state) => match($state) {
                        PurchaseOrderReception::WINMENTOR_PENDING => 'warning',
                        PurchaseOrderReception::WINMENTOR_FAILED  => 'danger',
                        default                                   => 'gray',
                    }),

                Tables\Columns\TextColumn::make('winmentor_synced_at')
                    ->label('Sincronizat la')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('winmentor_sync_error')
                    ->label('Eroare')
                    ->wrap()
                    ->color('danger')
                    ->toggleable(isToggledHiddenByDefault: false),
            ])
            ->actions([
                TableAction::make('retry')
                    ->label('Reîncearcă')
                    ->icon('heroicon-o-arrow-path')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalHeading(fn (PurchaseOrderReception $record) => "Reîncearcă sincronizare — {$record->purchaseOrder->number} #{$record->reception_number}")
                    ->modalDescription('Se va retrimite recepția către WinMentor Bridge.')
                    ->action(function (PurchaseOrderReception $record): void {
                        $record->update([
                            'winmentor_sync_status' => PurchaseOrderReception::WINMENTOR_PENDING,
                            'winmentor_sync_error'  => null,
                        ]);
                        Notification::make()->success()->title('Job dispatched, se reîncearcă sincronizarea.')->send();
                    }),

                TableAction::make('push_now')
                    ->label('Trimite acum')
                    ->icon('heroicon-o-arrow-up-circle')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading(fn (PurchaseOrderReception $record) => "Trimite sincron — {$record->purchaseOrder->number} #{$record->reception_number}")
                    ->modalDescription('Sincronizare directă (fără coadă). Poate dura câteva secunde.')
                    ->action(function (PurchaseOrderReception $record): void {
                        $service = new PushComenziFurnizoriService(new WinmentorBridgeClient());
                        $result  = $service->pushReception($record);

                        if ($result['success']) {
                            Notification::make()->success()->title('Trimis în WinMentor cu succes.')->send();
                        } else {
                            Notification::make()->danger()->title('Eroare WinMentor')->body($result['error'])->send();
                        }
                    }),

                TableAction::make('skip')
                    ->label('Ignoră')
                    ->icon('heroicon-o-x-circle')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading('Marchează ca ignorat?')
                    ->modalDescription('Recepția nu va mai apărea în coadă.')
                    ->action(function (PurchaseOrderReception $record): void {
                        $record->update([
                            'winmentor_sync_status' => PurchaseOrderReception::WINMENTOR_SYNCED,
                            'winmentor_sync_error'  => null,
                        ]);
                        Notification::make()->success()->title('Marcat ca ignorat.')->send();
                    }),
            ])
            ->bulkActions([
                BulkAction::make('retry_all')
                    ->label('Reîncearcă selectate')
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->action(function (\Illuminate\Support\Collection $records): void {
                        foreach ($records as $record) {
                            $record->update([
                                'winmentor_sync_status' => PurchaseOrderReception::WINMENTOR_PENDING,
                                'winmentor_sync_error'  => null,
                            ]);
                        }
                        Notification::make()->success()
                            ->title("{$records->count()} recepții trimise în coadă.")
                            ->send();
                    }),
            ])
            ->emptyStateHeading('Nicio recepție în așteptare')
            ->emptyStateDescription('Recepțiile cantitative vor apărea aici automat după finalizare.')
            ->emptyStateIcon('heroicon-o-check-circle');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('test_connection')
                ->label('Test conexiune Bridge')
                ->icon('heroicon-o-signal')
                ->color('gray')
                ->action(function (): void {
                    try {
                        $client = new WinmentorBridgeClient();
                        $health = $client->health();
                        if ($health['success'] ?? false) {
                            $data = $health['data'] ?? [];
                            Notification::make()->success()
                                ->title('Bridge accesibil')
                                ->body('COM: ' . (($data['comConnected'] ?? false) ? 'conectat' : 'deconectat'))
                                ->send();
                        } else {
                            Notification::make()->danger()->title('Bridge inaccesibil')->send();
                        }
                    } catch (\Throwable $e) {
                        Notification::make()->danger()->title('Eroare conectare')->body($e->getMessage())->send();
                    }
                }),
        ];
    }
}
