<?php

namespace App\Filament\App\Pages;

use App\Models\CategoryReviewProposal;
use App\Models\IntegrationConnection;
use App\Models\WooCategory;
use App\Services\WooCommerce\WooClient;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Actions\Action as TableAction;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;

class CategoryReviewPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon  = 'heroicon-o-tag';
    protected static string|\UnitEnum|null   $navigationGroup = 'Produse';
    protected static ?string $navigationLabel = 'Recategorizare produse';
    protected static ?int    $navigationSort  = 30;
    protected string         $view            = 'filament.app.pages.category-review';

    public static function shouldRegisterNavigation(): bool
    {
        $user = Auth::user();
        return $user && in_array($user->role, ['super_admin', 'admin']);
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();
        return $user && in_array($user->role, ['super_admin', 'admin']);
    }

    public static function getNavigationBadge(): ?string
    {
        $count = CategoryReviewProposal::where('status', CategoryReviewProposal::STATUS_PENDING)->count();
        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(CategoryReviewProposal::query()->with('product'))
            ->defaultSort('status')
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('woo_product_id')
                    ->label('ID Produs')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('product_name')
                    ->label('Produs')
                    ->searchable()
                    ->sortable()
                    ->limit(50)
                    ->tooltip(fn ($record) => $record->product_name)
                    ->url(fn ($record) => $record->product?->woo_id
                        ? route('filament.app.resources.produse.view', $record->woo_product_id)
                        : null),

                TextColumn::make('current_cats')
                    ->label('Categorie curentă')
                    ->searchable()
                    ->limit(40)
                    ->tooltip(fn ($record) => $record->current_cats)
                    ->color('gray'),

                TextColumn::make('suggested_cat_name')
                    ->label('Categorie propusă')
                    ->searchable()
                    ->weight('bold')
                    ->color('primary'),

                TextColumn::make('reason')
                    ->label('Motiv')
                    ->limit(60)
                    ->tooltip(fn ($record) => $record->reason)
                    ->wrap(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending'  => 'warning',
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default    => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending'  => 'În așteptare',
                        'approved' => 'Aprobat',
                        'rejected' => 'Respins',
                        default    => $state,
                    })
                    ->sortable(),

                TextColumn::make('reviewed_at')
                    ->label('Revizuit la')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'pending'  => 'În așteptare',
                        'approved' => 'Aprobat',
                        'rejected' => 'Respins',
                    ])
                    ->default('pending'),
            ])
            ->actions([
                TableAction::make('approve')
                    ->label('Aprobă')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn ($record) => $record->status === CategoryReviewProposal::STATUS_PENDING)
                    ->requiresConfirmation()
                    ->modalHeading(fn ($record) => 'Aprobă mutarea: ' . $record->product_name)
                    ->modalDescription(fn ($record) => 'Produsul va fi mutat din "' . $record->current_cats . '" in "' . $record->suggested_cat_name . '".')
                    ->action(fn ($record) => $this->approveProposal($record)),

                TableAction::make('reject')
                    ->label('Respinge')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn ($record) => $record->status === CategoryReviewProposal::STATUS_PENDING)
                    ->action(fn ($record) => $this->rejectProposal($record)),

                TableAction::make('undo')
                    ->label('Anulează')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('gray')
                    ->visible(fn ($record) => $record->status !== CategoryReviewProposal::STATUS_PENDING)
                    ->action(fn ($record) => $this->undoProposal($record)),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('bulk_approve')
                        ->label('Aprobă selectate')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(fn (Collection $records) => $this->bulkApprove($records))
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('bulk_reject')
                        ->label('Respinge selectate')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->action(fn (Collection $records) => $this->bulkReject($records))
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->headerActions([
                \Filament\Actions\Action::make('stats')
                    ->label(fn () => $this->getStatsLabel())
                    ->color('gray')
                    ->disabled(),
            ])
            ->striped()
            ->paginated([25, 50, 100]);
    }

    protected function getStatsLabel(): string
    {
        $pending  = CategoryReviewProposal::where('status', 'pending')->count();
        $approved = CategoryReviewProposal::where('status', 'approved')->count();
        $rejected = CategoryReviewProposal::where('status', 'rejected')->count();
        return "În așteptare: {$pending} | Aprobate: {$approved} | Respinse: {$rejected}";
    }

    protected function approveProposal(CategoryReviewProposal $proposal): void
    {
        // Get the current category IDs for this product
        $product = $proposal->product;
        if (! $product) {
            Notification::make()->title('Produs negăsit')->danger()->send();
            return;
        }

        // Check that suggested category exists
        $newCat = WooCategory::find($proposal->suggested_cat_id);
        if (! $newCat) {
            Notification::make()->title('Categoria propusă nu există')->danger()->send();
            return;
        }

        // Update local pivot
        $product->categories()->sync([$proposal->suggested_cat_id]);

        // Push to WooCommerce if the product has a woo_id
        if ($product->woo_id) {
            try {
                $connection = $product->connection;
                if ($connection) {
                    $client = new WooClient($connection);
                    $client->updateProduct($product->woo_id, [
                        'categories' => [['id' => $newCat->woo_id]],
                    ]);
                }
            } catch (\Throwable $e) {
                \Log::warning('[CategoryReview] Failed to push category to WooCommerce for product ' . $product->id . ': ' . $e->getMessage());
            }
        }

        $proposal->update([
            'status'      => CategoryReviewProposal::STATUS_APPROVED,
            'reviewed_by' => Auth::id(),
            'reviewed_at' => now(),
        ]);

        Notification::make()
            ->title('Produs recategorizat')
            ->body($product->name . ' -> ' . $proposal->suggested_cat_name)
            ->success()
            ->send();
    }

    protected function rejectProposal(CategoryReviewProposal $proposal): void
    {
        $proposal->update([
            'status'      => CategoryReviewProposal::STATUS_REJECTED,
            'reviewed_by' => Auth::id(),
            'reviewed_at' => now(),
        ]);

        Notification::make()
            ->title('Propunere respinsă')
            ->body($proposal->product_name)
            ->warning()
            ->send();
    }

    protected function undoProposal(CategoryReviewProposal $proposal): void
    {
        $proposal->update([
            'status'      => CategoryReviewProposal::STATUS_PENDING,
            'reviewed_by' => null,
            'reviewed_at' => null,
        ]);

        Notification::make()
            ->title('Propunere resetată la „În așteptare"')
            ->info()
            ->send();
    }

    protected function bulkApprove(Collection $records): void
    {
        $approved = 0;
        foreach ($records as $record) {
            if ($record->status !== CategoryReviewProposal::STATUS_PENDING) {
                continue;
            }
            $this->approveProposal($record);
            $approved++;
        }

        Notification::make()
            ->title("Au fost aprobate și aplicate {$approved} recategorizări")
            ->success()
            ->send();
    }

    protected function bulkReject(Collection $records): void
    {
        $count = $records->where('status', CategoryReviewProposal::STATUS_PENDING)->count();
        CategoryReviewProposal::whereIn('id', $records->pluck('id'))
            ->where('status', CategoryReviewProposal::STATUS_PENDING)
            ->update([
                'status'      => CategoryReviewProposal::STATUS_REJECTED,
                'reviewed_by' => Auth::id(),
                'reviewed_at' => now(),
            ]);

        Notification::make()
            ->title("{$count} propuneri respinse")
            ->warning()
            ->send();
    }
}
