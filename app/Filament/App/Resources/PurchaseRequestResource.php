<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Concerns\EnforcesLocationScope;
use App\Filament\App\Concerns\ChecksRolePermissions;
use App\Filament\App\Concerns\HasDynamicNavSort;
use App\Filament\App\Resources\PurchaseRequestResource\Pages;
use App\Filament\App\Resources\PurchaseOrderResource;
use App\Filament\App\Forms\Components\PurchaseItemsTable;
use App\Models\Location;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\User;
use App\Models\WooProduct;
use App\Notifications\PurchaseRequestSubmittedNotification;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

use Filament\Actions\Action as InfolistAction;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Actions as TableActions;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PurchaseRequestResource extends Resource
{
    use HasDynamicNavSort;

    use EnforcesLocationScope, ChecksRolePermissions;

    protected static ?string $model = PurchaseRequest::class;

    protected static string|\BackedEnum|null $navigationIcon   = 'heroicon-o-clipboard-document-list';
    protected static string|\UnitEnum|null $navigationGroup  = 'Achiziții';
    protected static ?string $navigationLabel  = 'Necesare';
    protected static ?string $modelLabel       = 'Necesar';
    protected static ?string $pluralModelLabel = 'Necesare';
    protected static ?int    $navigationSort   = 1;

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()
            ->where('status', PurchaseRequest::STATUS_SUBMITTED)
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make('Informații necesar')
                ->columnSpanFull()
                ->columns(3)
                ->schema([
                    TextInput::make('number')
                        ->label('Număr')
                        ->disabled()
                        ->placeholder('Se generează automat'),

                    Select::make('status')
                        ->label('Status')
                        ->options(PurchaseRequest::statusOptions())
                        ->disabled()
                        ->default(PurchaseRequest::STATUS_DRAFT),

                    Select::make('location_id')
                        ->label('Locație')
                        ->options(function (): array {
                            $user = static::currentUser();
                            if (! $user) return [];

                            if ($user->isSuperAdmin()) {
                                return Location::query()->pluck('name', 'id')->all();
                            }

                            return Location::query()
                                ->whereIn('id', $user->operationalLocationIds())
                                ->pluck('name', 'id')
                                ->all();
                        })
                        ->default(fn (): ?int => static::currentUser()?->location_id)
                        ->required()
                        ->searchable(),

                    Textarea::make('notes')
                        ->label('Observații')
                        ->rows(2)
                        ->columnSpanFull(),
                ]),

            Section::make('Produse solicitate')
                ->columnSpanFull()
                ->schema([
                    PurchaseItemsTable::make('items')
                        ->label(''),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('number')
                    ->label('Număr')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('location.name')
                    ->label('Locație')
                    ->sortable(),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Consultant')
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn ($state): string => PurchaseRequest::statusColors()[$state] ?? 'gray')
                    ->formatStateUsing(fn ($state): string => PurchaseRequest::statusOptions()[$state] ?? $state),

                Tables\Columns\TextColumn::make('items_count')
                    ->label('Produse')
                    ->counts('items')
                    ->sortable(),

                Tables\Columns\IconColumn::make('has_urgent')
                    ->label('Urgent')
                    ->boolean()
                    ->getStateUsing(fn (PurchaseRequest $record): bool => $record->items()->where('is_urgent', true)->exists())
                    ->trueColor('danger')
                    ->falseColor('gray'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Data')
                    ->date('d.m.Y')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(PurchaseRequest::statusOptions()),

                Tables\Filters\TernaryFilter::make('has_urgent')
                    ->label('Urgent')
                    ->queries(
                        true:  fn (Builder $q) => $q->whereHas('items', fn ($i) => $i->where('is_urgent', true)),
                        false: fn (Builder $q) => $q->whereDoesntHave('items', fn ($i) => $i->where('is_urgent', true)),
                    ),
            ])
            ->deferFilters(false)
            ->recordActions([
                TableActions\ViewAction::make(),
                TableActions\EditAction::make()
                    ->visible(fn (PurchaseRequest $record): bool => static::canEdit($record)),
                TableActions\DeleteAction::make()
                    ->visible(fn (PurchaseRequest $record): bool => static::canDelete($record))
                    ->requiresConfirmation(),
                Actions\Action::make('submit')
                    ->label('Trimite')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('success')
                    ->visible(fn (PurchaseRequest $record): bool => $record->status === PurchaseRequest::STATUS_DRAFT)
                    ->requiresConfirmation()
                    ->modalHeading('Trimiți necesarul spre buyer?')
                    ->modalDescription('După trimitere nu mai poți edita necesarul.')
                    ->modalSubmitActionLabel('Da, trimite')
                    ->action(function (PurchaseRequest $record): void {
                        $record->update(['status' => PurchaseRequest::STATUS_SUBMITTED]);
                        Notification::make()->success()->title('Necesarul a fost trimis spre buyer.')->send();

                        // Notificăm buyer-ii responsabili pentru furnizorii din necesar
                        $record->loadMissing('items');
                        $supplierIds = $record->items->pluck('supplier_id')->filter()->unique();

                        $buyers = User::query()
                            ->where(function ($q) use ($supplierIds): void {
                                if ($supplierIds->isNotEmpty()) {
                                    $q->whereHas('managedSuppliers', fn ($s) => $s->whereIn('id', $supplierIds));
                                }
                                $q->orWhere('role', User::ROLE_MANAGER_ACHIZITII);
                            })
                            ->where('id', '!=', auth()->id())
                            ->get();

                        // Fallback: dacă nu s-au găsit buyers specifici, notificăm toți managerii de achiziții
                        if ($buyers->isEmpty()) {
                            $buyers = User::query()
                                ->where('role', User::ROLE_MANAGER_ACHIZITII)
                                ->where('id', '!=', auth()->id())
                                ->get();
                        }

                        foreach ($buyers as $buyer) {
                            $buyer->notify(new PurchaseRequestSubmittedNotification($record));
                        }
                    }),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->schema([
            InfolistSection::make('Informații necesar')
                ->columns(3)
                ->schema([
                    TextEntry::make('number')->label('Număr'),
                    TextEntry::make('status')
                        ->label('Status')
                        ->badge()
                        ->color(fn ($state): string => PurchaseRequest::statusColors()[$state] ?? 'gray')
                        ->formatStateUsing(fn ($state): string => PurchaseRequest::statusOptions()[$state] ?? $state),
                    TextEntry::make('location.name')->label('Locație'),
                    TextEntry::make('user.name')->label('Creat de'),
                    TextEntry::make('created_at')->label('Data')->dateTime('d.m.Y H:i'),
                    TextEntry::make('notes')->label('Observații')->placeholder('—')->columnSpanFull(),
                ]),

            InfolistSection::make('Comunicare furnizori')
                ->collapsed()
                ->schema([
                    ViewEntry::make('supplier_emails_context')
                        ->label('')
                        ->view('filament.app.infolist.purchase-request-emails'),
                ]),

            InfolistSection::make('Produse solicitate')
                ->schema([
                    RepeatableEntry::make('items')
                        ->label('')
                        ->columns(4)
                        ->schema([
                            TextEntry::make('product_name')->label('Produs'),
                            TextEntry::make('supplier.name')->label('Furnizor')->placeholder('—'),
                            TextEntry::make('quantity')->label('Cant. cerută'),
                            TextEntry::make('ordered_quantity')
                                ->label('Cant. comandată')
                                ->formatStateUsing(fn ($state, PurchaseRequestItem $record): string =>
                                    (float) $state > 0
                                        ? number_format((float) $state, 0, ',', '.') . ' / ' . number_format((float) $record->quantity, 0, ',', '.')
                                        : '—'
                                )
                                ->color(fn (PurchaseRequestItem $record): string =>
                                    (float) $record->ordered_quantity <= 0 ? 'gray' :
                                    ($record->isFullyOrdered() ? 'success' : 'warning')
                                )
                                ->badge(),
                            TextEntry::make('needed_by')->label('Necesar până la')->date('d.m.Y')->placeholder('—'),
                            IconEntry::make('is_urgent')->label('Urgent')->boolean(),
                            IconEntry::make('is_reserved')->label('Rezervat')->boolean(),
                            TextEntry::make('customer.name')->label('Client rezervare')->placeholder('—'),
                            TextEntry::make('offer.number')->label('Ofertă rezervare')->placeholder('—')
                                ->badge()->color('info')
                                ->url(fn (PurchaseRequestItem $record): ?string =>
                                    $record->offer_id
                                        ? \App\Filament\App\Resources\OfferResource::getUrl('view', ['record' => $record->offer_id])
                                        : null
                                ),
                            TextEntry::make('status')
                                ->label('Status')
                                ->badge()
                                ->color(fn ($state): string => match ($state) {
                                    'pending'   => 'warning',
                                    'ordered'   => 'success',
                                    'cancelled' => 'danger',
                                    default     => 'gray',
                                })
                                ->formatStateUsing(fn ($state): string => match ($state) {
                                    'pending'   => 'În așteptare',
                                    'ordered'   => 'Comandat',
                                    'cancelled' => 'Anulat',
                                    default     => $state,
                                }),
                            TextEntry::make('purchaseOrderItem.purchaseOrder.number')
                                ->label('PO')
                                ->placeholder('—')
                                ->badge()
                                ->color('info')
                                ->url(fn (PurchaseRequestItem $record): ?string =>
                                    $record->purchaseOrderItem?->purchaseOrder?->id
                                        ? PurchaseOrderResource::getUrl('view', ['record' => $record->purchaseOrderItem->purchaseOrder->id])
                                        : null
                                ),
                            TextEntry::make('notes')->label('Notițe')->placeholder('—'),
                            Actions::make([
                                InfolistAction::make('edit_quantity')
                                    ->label('Modifică cantitate')
                                    ->icon('heroicon-o-pencil-square')
                                    ->color('warning')
                                    ->size(\Filament\Support\Enums\ActionSize::Small)
                                    ->visible(fn (PurchaseRequestItem $record): bool =>
                                        $record->status === PurchaseRequestItem::STATUS_PENDING
                                    )
                                    ->form([
                                        TextInput::make('quantity')
                                            ->label('Cantitate nouă')
                                            ->numeric()
                                            ->minValue(0.001)
                                            ->step('any')
                                            ->required()
                                            ->default(fn (PurchaseRequestItem $record): float => $record->quantity),
                                    ])
                                    ->fillForm(fn (PurchaseRequestItem $record): array => [
                                        'quantity' => $record->quantity,
                                    ])
                                    ->action(function (PurchaseRequestItem $record, array $data): void {
                                        $record->update(['quantity' => max(0.001, (float) $data['quantity'])]);
                                        Notification::make()->success()->title('Cantitatea a fost actualizată.')->send();
                                    }),

                                InfolistAction::make('cancel_item')
                                    ->label('Șterge produs')
                                    ->icon('heroicon-o-x-circle')
                                    ->color('danger')
                                    ->size(\Filament\Support\Enums\ActionSize::Small)
                                    ->visible(fn (PurchaseRequestItem $record): bool =>
                                        $record->status === PurchaseRequestItem::STATUS_PENDING
                                    )
                                    ->requiresConfirmation()
                                    ->modalHeading('Ștergi acest produs din necesar?')
                                    ->modalDescription(fn (PurchaseRequestItem $record): string =>
                                        'Produsul "' . $record->product_name . '" va fi marcat ca anulat.'
                                    )
                                    ->modalSubmitActionLabel('Da, șterge')
                                    ->action(function (PurchaseRequestItem $record): void {
                                        $record->update(['status' => PurchaseRequestItem::STATUS_CANCELLED]);
                                        $record->purchaseRequest->recalculateStatus();
                                        Notification::make()->success()->title('Produsul a fost șters din necesar.')->send();
                                    }),
                            ]),
                        ]),
                ]),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = static::applyLocationFilter(
            parent::getEloquentQuery()->with(['user', 'location', 'items.purchaseOrderItem.purchaseOrder'])
        );

        if (auth()->user()?->role === \App\Models\User::ROLE_CONSULTANT_VANZARI) {
            $query->where('user_id', auth()->id());
        }

        return $query;
    }

    public static function canCreate(): bool
    {
        return auth()->check();
    }

    public static function canEdit(Model $record): bool
    {
        $user = static::currentUser();
        if (! $user) return false;

        if ($record->status !== PurchaseRequest::STATUS_DRAFT) return false;

        return $user->isSuperAdmin() || $user->isAdmin() || $record->user_id === $user->id;
    }

    public static function canDelete(Model $record): bool
    {
        $user = static::currentUser();
        if (! $user) return false;

        // Nimeni nu poate șterge necesare în statusuri active (submitted, parțial/complet comandat)
        if (! in_array($record->status, [
            PurchaseRequest::STATUS_DRAFT,
            PurchaseRequest::STATUS_CANCELLED,
        ], true)) {
            return false;
        }

        // Din draft/cancelled: admin și super_admin pot șterge orice
        if ($user->isSuperAdmin() || $user->isAdmin()) return true;

        // Ceilalți doar înregistrările proprii
        return $record->user_id === $user->id;
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListPurchaseRequests::route('/'),
            'create' => Pages\CreatePurchaseRequest::route('/create'),
            'view'   => Pages\ViewPurchaseRequest::route('/{record}'),
            'edit'   => Pages\EditPurchaseRequest::route('/{record}/edit'),
        ];
    }
}
