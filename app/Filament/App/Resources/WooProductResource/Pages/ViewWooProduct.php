<?php

namespace App\Filament\App\Resources\WooProductResource\Pages;

use App\Filament\App\Resources\PurchaseRequestResource;
use App\Filament\App\Resources\WooProductResource;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Models\WooProduct;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewWooProduct extends ViewRecord
{
    protected static string $resource = WooProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('add_to_necesar')
                ->label('Adaugă la necesar')
                ->icon('heroicon-o-shopping-cart')
                ->color('danger')
                ->extraAttributes(['class' => 'hidden'])
                ->modalHeading(fn () => 'Adaugă la necesar: '.($this->record->decoded_name ?? $this->record->name))
                ->modalSubmitActionLabel('Adaugă')
                ->form(WooProductResource::necesarModalForm())
                ->action(function (array $data): void {
                    $user = auth()->user();
                    if (! $user instanceof User) {
                        return;
                    }

                    /** @var WooProduct $product */
                    $product  = $this->record;
                    $draft    = PurchaseRequest::getOrCreateDraft($user);
                    $existing = $draft->items()->where('woo_product_id', $product->id)->first();

                    if ($existing) {
                        $existing->update(['quantity' => (float) $existing->quantity + (float) $data['quantity']]);
                    } else {
                        $draft->items()->create([
                            'woo_product_id' => $product->id,
                            'quantity'       => $data['quantity'],
                            'is_urgent'      => $data['is_urgent'] ?? false,
                            'notes'          => $data['notes'] ?? null,
                        ]);
                    }

                    Notification::make()
                        ->success()
                        ->title('Produs adăugat la necesar')
                        ->actions([
                            \Filament\Notifications\Actions\Action::make('open_cart')
                                ->label('Deschide coșul →')
                                ->url(PurchaseRequestResource::getUrl('edit', ['record' => $draft->id])),
                        ])
                        ->send();
                }),
        ];
    }
}
