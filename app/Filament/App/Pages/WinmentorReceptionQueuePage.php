<?php

namespace App\Filament\App\Pages;

use App\Jobs\PushComenziFurnizoriToWinmentorJob;
use App\Models\PurchaseOrder;
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
                PurchaseOrder::query()
                    ->whereIn('winmentor_sync_status', [
                        PurchaseOrder::WINMENTOR_PENDING,
                        PurchaseOrder::WINMENTOR_FAILED,
                    ])
                    ->orWhere(function (Builder $q) {
                        // Include și PO-uri received fără sync status setat (receptii cantitative mai vechi)
                        $q->where('status', PurchaseOrder::STATUS_RECEIVED)
                          ->whereNull('winmentor_sync_status');
                    })
                    ->with(['supplier', 'items'])
                    ->latest('received_at')
            )
            ->columns([
                Tables\Columns\TextColumn::make('number')
                    ->label('Nr. comandă')
                    ->searchable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('supplier.name')
                    ->label('Furnizor')
                    ->searchable(),

                Tables\Columns\TextColumn::make('received_at')
                    ->label('Data recepție')
                    ->date('d.m.Y')
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
                        PurchaseOrder::WINMENTOR_PENDING => 'În așteptare',
                        PurchaseOrder::WINMENTOR_FAILED  => 'Eroare',
                        null                             => 'Nesincronizat',
                        default                          => $state,
                    })
                    ->color(fn ($state) => match($state) {
                        PurchaseOrder::WINMENTOR_PENDING => 'warning',
                        PurchaseOrder::WINMENTOR_FAILED  => 'danger',
                        default                          => 'gray',
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
                    ->visible(fn ($record) => filled($record?->winmentor_sync_error))
                    ->toggleable(isToggledHiddenByDefault: false),
            ])
            ->actions([
                TableAction::make('verify_and_push')
                    ->label('Verifică & Trimite')
                    ->icon('heroicon-o-arrow-up-circle')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalHeading(fn (PurchaseOrder $record) => "Trimite în WinMentor — {$record->number}")
                    ->modalDescription('Se va verifica dacă furnizorul și toate produsele există în WinMentor. Produsele lipsă vor fi create automat.')
                    ->action(fn (PurchaseOrder $record) => $this->pushToWinmentor($record)),

                TableAction::make('skip')
                    ->label('Ignoră')
                    ->icon('heroicon-o-x-circle')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading('Marchează ca ignorat?')
                    ->modalDescription('PO-ul nu va mai apărea în această coadă. Poate fi reactivat din pagina comenzii.')
                    ->action(function (PurchaseOrder $record): void {
                        $record->update([
                            'winmentor_sync_status' => PurchaseOrder::WINMENTOR_SYNCED,
                            'winmentor_sync_error'  => null,
                        ]);
                        Notification::make()->success()->title('Marcat ca ignorat.')->send();
                    }),
            ])
            ->bulkActions([
                BulkAction::make('push_all')
                    ->label('Trimite selectate în WinMentor')
                    ->icon('heroicon-o-arrow-up-circle')
                    ->requiresConfirmation()
                    ->action(function (\Illuminate\Support\Collection $records): void {
                        $ok      = 0;
                        $failed  = 0;
                        foreach ($records as $record) {
                            $result = $this->pushToWinmentor($record, silent: true);
                            $result ? $ok++ : $failed++;
                        }
                        Notification::make()
                            ->title("{$ok} trimise cu succes" . ($failed > 0 ? ", {$failed} cu erori." : '.'))
                            ->{$failed > 0 ? 'warning' : 'success'}()
                            ->send();
                    }),
            ])
            ->emptyStateHeading('Nicio recepție în așteptare')
            ->emptyStateDescription('Recepțiile cantitative vor apărea aici automat după finalizare.')
            ->emptyStateIcon('heroicon-o-check-circle');
    }

    private function pushToWinmentor(PurchaseOrder $record, bool $silent = false): bool
    {
        try {
            $service = new PushComenziFurnizoriService(new WinmentorBridgeClient());
            $result  = $service->push($record);

            if ($result['success']) {
                if (! $silent) {
                    Notification::make()->success()->title('Trimis în WinMentor cu succes.')->send();
                }
                return true;
            }

            if (! $silent) {
                Notification::make()->danger()->title('Eroare WinMentor')->body($result['error'])->send();
            }
            return false;

        } catch (\Throwable $e) {
            $record->update([
                'winmentor_sync_status' => PurchaseOrder::WINMENTOR_FAILED,
                'winmentor_sync_error'  => $e->getMessage(),
            ]);
            if (! $silent) {
                Notification::make()->danger()->title('Eroare WinMentor')->body($e->getMessage())->send();
            }
            return false;
        }
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
