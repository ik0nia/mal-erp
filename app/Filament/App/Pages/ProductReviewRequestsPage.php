<?php

namespace App\Filament\App\Pages;

use App\Models\ProductReviewRequest;
use App\Models\WooProduct;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Tables\Actions\Action as TableAction;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

class ProductReviewRequestsPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon  = 'heroicon-o-exclamation-triangle';
    protected static ?string $navigationGroup = 'Produse';
    protected static ?string $navigationLabel = 'Reverificări produse';
    protected static ?int    $navigationSort  = 30;
    protected static string  $view            = 'filament.app.pages.product-review-requests';

    public static function getNavigationBadge(): ?string
    {
        $count = ProductReviewRequest::where('status', ProductReviewRequest::STATUS_PENDING)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                ProductReviewRequest::query()
                    ->with(['product', 'user', 'resolvedBy'])
                    ->latest()
            )
            ->columns([
                TextColumn::make('product.name')
                    ->label('Produs')
                    ->formatStateUsing(fn (ProductReviewRequest $record): string => $record->product?->decoded_name ?? $record->product?->name ?? '-')
                    ->searchable(query: fn ($query, string $search) => $query->whereHas('product', fn ($q) => $q->where('name', 'like', "%{$search}%")))
                    ->url(fn (ProductReviewRequest $record): string => $record->product
                        ? \App\Filament\App\Resources\WooProductResource::getUrl('view', ['record' => $record->product])
                        : '#')
                    ->wrap()
                    ->weight('semibold'),

                TextColumn::make('product.sku')
                    ->label('SKU')
                    ->placeholder('-'),

                TextColumn::make('message')
                    ->label('Mesaj')
                    ->wrap()
                    ->limit(120),

                TextColumn::make('user.name')
                    ->label('Solicitat de')
                    ->badge()
                    ->color('info'),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'pending' ? 'În așteptare' : 'Rezolvat')
                    ->color(fn (string $state): string => $state === 'pending' ? 'warning' : 'success'),

                TextColumn::make('created_at')
                    ->label('Data')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),

                TextColumn::make('resolvedBy.name')
                    ->label('Rezolvat de')
                    ->placeholder('-')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'pending'  => 'În așteptare',
                        'resolved' => 'Rezolvat',
                    ])
                    ->default('pending'),
            ])
            ->actions([
                TableAction::make('resolve')
                    ->label('Marchează rezolvat')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (ProductReviewRequest $record): bool => $record->status === ProductReviewRequest::STATUS_PENDING)
                    ->requiresConfirmation()
                    ->action(function (ProductReviewRequest $record): void {
                        $record->update([
                            'status'               => ProductReviewRequest::STATUS_RESOLVED,
                            'resolved_by_user_id'  => auth()->id(),
                            'resolved_at'          => Carbon::now(),
                        ]);
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->striped()
            ->paginated([25, 50, 100]);
    }
}
