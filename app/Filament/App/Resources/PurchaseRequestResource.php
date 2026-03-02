<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Concerns\EnforcesLocationScope;
use App\Filament\App\Concerns\ChecksRolePermissions;
use App\Filament\App\Concerns\HasDynamicNavSort;
use App\Filament\App\Resources\PurchaseRequestResource\Pages;
use App\Models\Location;
use App\Models\ProductSupplier;
use App\Models\PurchaseRequest;
use App\Models\Supplier;
use App\Models\User;
use App\Models\WooProduct;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use App\Models\PurchaseRequestItem;
use Filament\Infolists\Components\Actions;
use Filament\Infolists\Components\Actions\Action as InfolistAction;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PurchaseRequestResource extends Resource
{
    use HasDynamicNavSort;

    use EnforcesLocationScope, ChecksRolePermissions;

    protected static ?string $model = PurchaseRequest::class;

    protected static ?string $navigationIcon   = 'heroicon-o-clipboard-document-list';
    protected static ?string $navigationGroup  = 'Achiziții';
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

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Informații necesar')
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
                ->schema([
                    Repeater::make('items')
                        ->label('')
                        ->relationship('items')
                        ->columns(['default' => 1, 'sm' => 2])
                        ->schema([
                            Select::make('woo_product_id')
                                ->label('Produs')
                                ->searchable()
                                ->columnSpanFull()
                                ->getSearchResultsUsing(function (string $search): array {
                                    return WooProduct::query()
                                        ->where(function (Builder $q) use ($search): void {
                                            $q->where('name', 'like', "%{$search}%")
                                                ->orWhere('sku', 'like', "%{$search}%");
                                        })
                                        ->limit(30)
                                        ->get()
                                        ->mapWithKeys(fn (WooProduct $p): array => [
                                            $p->id => "[{$p->sku}] ".($p->decoded_name ?? $p->name),
                                        ])
                                        ->all();
                                })
                                ->getOptionLabelUsing(function ($value): ?string {
                                    $p = WooProduct::query()->find($value, ['id', 'name', 'sku']);
                                    return $p ? "[{$p->sku}] ".($p->decoded_name ?? $p->name) : null;
                                })
                                ->live()
                                ->afterStateUpdated(function ($state, Set $set): void {
                                    if (! $state) return;

                                    $ps = ProductSupplier::where('woo_product_id', $state)
                                        ->orderByDesc('is_preferred')
                                        ->first();

                                    if ($ps) {
                                        $set('supplier_id', $ps->supplier_id);
                                    }
                                }),

                            Select::make('supplier_id')
                                ->label('Furnizor')
                                ->options(fn (): array => Supplier::query()
                                    ->where('is_active', true)
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->all()
                                )
                                ->columnSpanFull()
                                ->searchable()
                                ->nullable(),

                            TextInput::make('quantity')
                                ->label('Cantitate')
                                ->numeric()
                                ->minValue(0.001)
                                ->required()
                                ->default(1),

                            DatePicker::make('needed_by')
                                ->label('Necesar până la')
                                ->nullable()
                                ->displayFormat('d.m.Y'),

                            Toggle::make('is_urgent')
                                ->label('Urgent')
                                ->default(false),

                            Toggle::make('is_reserved')
                                ->label('Rezervat')
                                ->live()
                                ->default(false),

                            Select::make('customer_id')
                                ->label('Client rezervare')
                                ->searchable()
                                ->columnSpanFull()
                                ->getSearchResultsUsing(fn (string $search): array => \App\Models\Customer::query()
                                    ->where(function (\Illuminate\Database\Eloquent\Builder $q) use ($search): void {
                                        $q->where('name', 'like', "%{$search}%")
                                            ->orWhere('phone', 'like', "%{$search}%")
                                            ->orWhere('email', 'like', "%{$search}%");
                                    })
                                    ->limit(30)
                                    ->pluck('name', 'id')
                                    ->all()
                                )
                                ->getOptionLabelUsing(fn ($value): ?string => \App\Models\Customer::query()->find($value)?->name)
                                ->disabled(fn (Get $get): bool => ! (bool) $get('is_reserved'))
                                ->nullable(),

                            TextInput::make('notes')
                                ->label('Notițe')
                                ->nullable(),
                        ])
                        ->itemLabel(function (array $state): string {
                            if (empty($state['woo_product_id'])) {
                                return 'Produs nou';
                            }
                            $p = WooProduct::query()->whereKey($state['woo_product_id'])->first(['name', 'sku']);
                            return $p ? ($p->sku ? "[{$p->sku}] " : '') . ($p->decoded_name ?? $p->name) : 'Produs';
                        })
                        ->collapsible()
                        ->defaultItems(1)
                        ->addActionLabel('+ Adaugă produs'),
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
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make()
                    ->visible(fn (PurchaseRequest $record): bool => static::canEdit($record)),
                Tables\Actions\Action::make('submit')
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
                    }),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
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

            InfolistSection::make('Produse solicitate')
                ->schema([
                    RepeatableEntry::make('items')
                        ->label('')
                        ->columns(4)
                        ->schema([
                            TextEntry::make('product_name')->label('Produs'),
                            TextEntry::make('supplier.name')->label('Furnizor')->placeholder('—'),
                            TextEntry::make('quantity')->label('Cantitate'),
                            TextEntry::make('needed_by')->label('Necesar până la')->date('d.m.Y')->placeholder('—'),
                            IconEntry::make('is_urgent')->label('Urgent')->boolean(),
                            IconEntry::make('is_reserved')->label('Rezervat')->boolean(),
                            TextEntry::make('customer.name')->label('Client rezervare')->placeholder('—'),
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
                            TextEntry::make('notes')->label('Notițe')->placeholder('—'),
                            Actions::make([
                                InfolistAction::make('cancel_item')
                                    ->label('Anulează item')
                                    ->icon('heroicon-o-x-circle')
                                    ->color('danger')
                                    ->size(\Filament\Support\Enums\ActionSize::Small)
                                    ->visible(fn (PurchaseRequestItem $record): bool =>
                                        $record->status === PurchaseRequestItem::STATUS_PENDING
                                    )
                                    ->requiresConfirmation()
                                    ->modalHeading('Anulezi acest produs din necesar?')
                                    ->modalDescription(fn (PurchaseRequestItem $record): string =>
                                        'Produsul "' . $record->product_name . '" va fi marcat ca anulat.'
                                    )
                                    ->modalSubmitActionLabel('Da, anulează')
                                    ->action(function (PurchaseRequestItem $record): void {
                                        $record->update(['status' => PurchaseRequestItem::STATUS_CANCELLED]);
                                        $record->purchaseRequest->recalculateStatus();
                                        Notification::make()->success()->title('Produsul a fost anulat din necesar.')->send();
                                    }),
                            ]),
                        ]),
                ]),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = static::applyLocationFilter(
            parent::getEloquentQuery()->with(['user', 'location', 'items'])
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

        // Admin și super_admin pot șterge orice necesar (indiferent de status)
        if ($user->isSuperAdmin() || $user->isAdmin()) return true;

        // Ceilalți doar draft-urile proprii
        return $record->status === PurchaseRequest::STATUS_DRAFT
            && $record->user_id === $user->id;
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
