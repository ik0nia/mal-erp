<?php

namespace App\Filament\App\Resources\OfferResource\Pages;

use App\Filament\App\Resources\OfferResource;
use App\Mail\OfferMail;
use App\Models\Offer;
use App\Models\User;
use App\Services\Offers\OfferPdf;
use Filament\Actions;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ViewOffer extends ViewRecord
{
    protected static string $resource = OfferResource::class;

    protected function canApproveDiscounts(): bool
    {
        $user = auth()->user();

        return $user instanceof User && (
            $user->isAdmin() || $user->isSuperAdmin()
            || in_array($user->role, [
                User::ROLE_MANAGER,
                User::ROLE_DIRECTOR_VANZARI,
            ], true)
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            // ── Aprobare discount ──────────────────────────────────────────
            Actions\Action::make('approve_discount')
                ->label('Aprobă discount')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->visible(fn (): bool => $this->record->needsDiscountApproval() && $this->canApproveDiscounts())
                ->requiresConfirmation()
                ->modalHeading('Aprobă discountul ofertei')
                ->form([
                    Textarea::make('note')->label('Notă (opțional)')->rows(2),
                ])
                ->action(function (array $data): void {
                    $this->record->update([
                        'approval_status' => Offer::APPROVAL_APPROVED,
                        'approved_by'     => auth()->id(),
                        'approved_at'     => now(),
                        'approval_note'   => $data['note'] ?? null,
                        'approved_discount_level' => (float) $this->record->items()->max('discount_percent'),
                    ]);

                    $this->record->user?->notify(new \App\Notifications\OfferApprovalDecisionNotification(
                        offer: $this->record, approved: true, note: $data['note'] ?? null,
                    ));

                    Notification::make()->success()->title('Discount aprobat.')->send();
                    $this->refreshFormData(['approval_status']);
                }),

            Actions\Action::make('reject_discount')
                ->label('Respinge discount')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (): bool => $this->record->needsDiscountApproval() && $this->canApproveDiscounts())
                ->requiresConfirmation()
                ->modalHeading('Respinge discountul ofertei')
                ->form([
                    Textarea::make('note')->label('Motiv')->rows(2)->required(),
                ])
                ->action(function (array $data): void {
                    $this->record->update([
                        'approval_status' => Offer::APPROVAL_REJECTED,
                        'approved_by'     => auth()->id(),
                        'approved_at'     => now(),
                        'approval_note'   => $data['note'] ?? null,
                    ]);

                    $this->record->user?->notify(new \App\Notifications\OfferApprovalDecisionNotification(
                        offer: $this->record, approved: false, note: $data['note'] ?? null,
                    ));

                    Notification::make()->warning()->title('Discount respins.')->send();
                    $this->refreshFormData(['approval_status']);
                }),

            // ── Trimite pe email ───────────────────────────────────────────
            Actions\Action::make('send_email')
                ->label('Trimite pe email')
                ->icon('heroicon-o-paper-airplane')
                ->color('primary')
                ->disabled(fn (): bool => $this->record->isBlockedForSending())
                ->tooltip(fn (): ?string => $this->record->isBlockedForSending()
                    ? 'Oferta are discount care așteaptă aprobare.' : null)
                ->form(function (): array {
                    $offer = $this->record;

                    $body  = "Bună ziua,\n\n";
                    $body .= "Vă transmitem atașat oferta noastră comercială nr. {$offer->number}";
                    $body .= $offer->valid_until ? ", valabilă până la {$offer->valid_until->format('d.m.Y')}.\n\n" : ".\n\n";
                    $body .= "Valoarea totală a ofertei este de " . number_format((float) $offer->total, 2, ',', '.') . " {$offer->currency} (cu TVA inclus).\n\n";
                    $body .= "Rămânem la dispoziția dumneavoastră pentru orice detaliu.\n\n";
                    $body .= 'Cu stimă,' . "\n" . (auth()->user()?->name ?? 'Echipa de vânzări') . "\n" . ($offer->location?->company_name ?: 'Malinco Prodex S.R.L.');

                    return [
                        TextInput::make('to_email')
                            ->label('Destinatar')
                            ->email()
                            ->required()
                            ->default($offer->client_email),
                        TextInput::make('subject')
                            ->label('Subiect')
                            ->required()
                            ->default("Ofertă comercială {$offer->number}"),
                        Textarea::make('body')
                            ->label('Mesaj')
                            ->rows(12)
                            ->required()
                            ->default($body),
                    ];
                })
                ->action(function (array $data): void {
                    try {
                        OfferPdf::invalidate($this->record);

                        Mail::to($data['to_email'])->send(new OfferMail(
                            emailSubject: $data['subject'],
                            emailBody:    $data['body'],
                            offer:        $this->record,
                        ));

                        if ($this->record->status === Offer::STATUS_DRAFT) {
                            $this->record->update([
                                'status'  => Offer::STATUS_SENT,
                                'sent_at' => now(),
                            ]);
                        }

                        Notification::make()->success()
                            ->title('Ofertă trimisă.')
                            ->body("Trimisă la: {$data['to_email']}")
                            ->send();

                        $this->refreshFormData(['status']);
                    } catch (\Throwable $e) {
                        Notification::make()->danger()
                            ->title('Eroare la trimitere.')
                            ->body($e->getMessage())
                            ->send();
                    }
                }),

            // ── Descarcă PDF ───────────────────────────────────────────────
            Actions\Action::make('download_pdf')
                ->label('Descarcă PDF')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(function (): StreamedResponse {
                    OfferPdf::invalidate($this->record);
                    $content  = OfferPdf::get($this->record);
                    $filename = OfferPdf::filename($this->record);

                    return response()->streamDownload(
                        fn () => print($content),
                        $filename,
                        ['Content-Type' => 'application/pdf'],
                    );
                }),

            Actions\Action::make('print')
                ->label('Print')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->url(fn (): string => OfferResource::getUrl('print', [
                    'record' => $this->record,
                    'auto_print' => 1,
                ]))
                ->openUrlInNewTab(),

            // ── Ciclu de viață (decizia clientului) ────────────────────────
            Actions\Action::make('mark_accepted')
                ->label('Marchează acceptată')
                ->icon('heroicon-o-hand-thumb-up')
                ->color('success')
                ->visible(fn (): bool => in_array($this->record->status, [Offer::STATUS_SENT, Offer::STATUS_DRAFT], true))
                ->requiresConfirmation()
                ->action(function (): void {
                    $this->record->update(['status' => Offer::STATUS_ACCEPTED]);
                    Notification::make()->success()->title('Ofertă marcată ca acceptată.')->send();
                    $this->refreshFormData(['status']);
                }),

            Actions\Action::make('mark_rejected')
                ->label('Marchează respinsă')
                ->icon('heroicon-o-hand-thumb-down')
                ->color('danger')
                ->visible(fn (): bool => in_array($this->record->status, [Offer::STATUS_SENT, Offer::STATUS_DRAFT], true))
                ->requiresConfirmation()
                ->action(function (): void {
                    $this->record->update(['status' => Offer::STATUS_REJECTED]);
                    Notification::make()->warning()->title('Ofertă marcată ca respinsă.')->send();
                    $this->refreshFormData(['status']);
                }),

            // ── Reactivează ofertă expirată ────────────────────────────────
            Actions\Action::make('reactivate')
                ->label('Reactivează')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->visible(fn (): bool => $this->record->status === Offer::STATUS_EXPIRED)
                ->form([
                    \Filament\Forms\Components\DatePicker::make('valid_until')
                        ->label('Noua valabilitate')
                        ->native(false)
                        ->default(now()->addDays(14))
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $this->record->update([
                        'status'      => Offer::STATUS_DRAFT,
                        'valid_until' => $data['valid_until'],
                    ]);
                    Notification::make()->success()->title('Ofertă reactivată (draft).')->send();
                    $this->refreshFormData(['status', 'valid_until']);
                }),

            // ── Duplică ────────────────────────────────────────────────────
            Actions\Action::make('duplicate')
                ->label('Duplică')
                ->icon('heroicon-o-document-duplicate')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('Se creează o ofertă nouă (draft) cu aceleași produse și condiții.')
                ->action(function () {
                    $new = $this->record->replicate([
                        'number', 'status', 'sent_at', 'accepted_at',
                        'approval_status', 'approved_by', 'approved_at', 'approval_note', 'approval_notified_at',
                    ]);
                    $new->number = null;
                    $new->status = Offer::STATUS_DRAFT;
                    $new->user_id = auth()->id();
                    $new->save();

                    foreach ($this->record->items as $item) {
                        $copy = $item->replicate(['offer_id']);
                        $copy->offer_id = $new->id;
                        $copy->saveQuietly();
                    }

                    $new->recalculateTotals();

                    Notification::make()->success()
                        ->title('Ofertă duplicată.')
                        ->body("Ofertă nouă: {$new->number}")
                        ->send();

                    return redirect(OfferResource::getUrl('edit', ['record' => $new]));
                }),

            Actions\EditAction::make(),
        ];
    }
}
