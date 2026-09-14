<?php

namespace App\Filament\App\Pages;

use App\Filament\App\Resources\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Raport de conformitate flux PO (pentru admini): detectează abateri de procedură —
 * recepție înainte de trimiterea comenzii, PO creat retroactiv (aproape de recepție),
 * recepție ultra-rapidă după trimitere. Arată cine și de câte ori.
 */
class PoComplianceReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shield-exclamation';
    protected static string|\UnitEnum|null $navigationGroup = 'Rapoarte';
    protected static ?string $navigationLabel = 'Conformitate PO';
    protected static ?string $title = 'Conformitate flux comenzi furnizori';
    protected string $view = 'filament.app.pages.po-compliance-report';

    public static function canAccess(): bool
    {
        $u = auth()->user();
        return $u instanceof \App\Models\User && ($u->isSuperAdmin() || $u->isAdmin());
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function getNavigationBadge(): ?string
    {
        $n = static::baseQuery()->count();
        return $n > 0 ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    /** PO-urile cu cel puțin o abatere de flux. */
    public static function baseQuery(): Builder
    {
        $recSub = DB::table('purchase_order_receptions')
            ->selectRaw('purchase_order_id, MIN(received_at) first_reception')
            ->groupBy('purchase_order_id');

        return PurchaseOrder::query()
            ->leftJoinSub($recSub, 'r', 'r.purchase_order_id', '=', 'purchase_orders.id')
            ->whereNotNull('r.first_reception')
            ->where(function ($q) {
                $q->whereRaw('purchase_orders.sent_at IS NOT NULL AND r.first_reception < purchase_orders.sent_at')
                    ->orWhereRaw('purchase_orders.sent_at IS NOT NULL AND TIMESTAMPDIFF(MINUTE, purchase_orders.sent_at, r.first_reception) BETWEEN 0 AND 60')
                    ->orWhereRaw('ABS(TIMESTAMPDIFF(HOUR, purchase_orders.created_at, r.first_reception)) < 2');
            })
            ->select('purchase_orders.*', 'r.first_reception');
    }

    /** Etichetele abaterilor pentru un PO. */
    public static function anomalies(PurchaseOrder $po): array
    {
        $rec = $po->first_reception ? \Carbon\Carbon::parse($po->first_reception) : null;
        if (! $rec) {
            return [];
        }
        $out = [];
        if ($po->sent_at && $rec->lt($po->sent_at)) {
            $out[] = ['Recepție înainte de trimitere', 'danger'];
        } elseif ($po->sent_at && $rec->diffInMinutes($po->sent_at) <= 60 && $rec->gte($po->sent_at)) {
            $out[] = ['Recepție <1h după trimitere', 'warning'];
        }
        if ($po->created_at && abs($rec->diffInHours($po->created_at, false)) < 2) {
            $out[] = ['PO retroactiv (creat lângă recepție)', 'danger'];
        }
        return $out;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(static::baseQuery())
            ->defaultSort('first_reception', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('number')
                    ->label('PO')
                    ->weight(\Filament\Support\Enums\FontWeight::Bold)
                    ->url(fn (PurchaseOrder $r): string => PurchaseOrderResource::getUrl('view', ['record' => $r->id]))
                    ->color('primary')
                    ->searchable(),
                Tables\Columns\TextColumn::make('supplier.name')->label('Furnizor')->limit(28)->searchable(),
                Tables\Columns\TextColumn::make('anomalii')
                    ->label('Abatere')
                    ->badge()
                    ->getStateUsing(fn (PurchaseOrder $r): array => array_map(fn ($a) => $a[0], static::anomalies($r)))
                    ->color(fn ($state, PurchaseOrder $r): string => static::anomalies($r)[0][1] ?? 'gray'),
                Tables\Columns\TextColumn::make('buyer.name')->label('Cumpărător')->placeholder('—')->searchable(),
                Tables\Columns\TextColumn::make('created_at')->label('PO creat')->dateTime('d.m.Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('sent_at')->label('Trimis')->dateTime('d.m.Y H:i')->placeholder('—')->sortable(),
                Tables\Columns\TextColumn::make('first_reception')->label('Prima recepție')->dateTime('d.m.Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('received_by_name')
                    ->label('Recepționat de')
                    ->getStateUsing(fn (PurchaseOrder $r): string => \App\Models\User::find($r->received_by)?->name ?? '—'),
                Tables\Columns\TextColumn::make('total_value')->label('Valoare')->money('RON')->sortable(),
            ])
            ->paginated([25, 50, 100]);
    }

    /** Sumar pentru header: nr. abateri per tip + per cumpărător. */
    public function getStats(): array
    {
        $rows = static::baseQuery()->with('buyer')->get();
        $byType = ['Recepție înainte de trimitere' => 0, 'Recepție <1h după trimitere' => 0, 'PO retroactiv (creat lângă recepție)' => 0];
        $byBuyer = [];
        foreach ($rows as $po) {
            foreach (static::anomalies($po) as $a) {
                $byType[$a[0]] = ($byType[$a[0]] ?? 0) + 1;
            }
            $name = $po->buyer?->name ?? '(necunoscut)';
            $byBuyer[$name] = ($byBuyer[$name] ?? 0) + 1;
        }
        arsort($byBuyer);
        return ['total' => $rows->count(), 'byType' => $byType, 'byBuyer' => $byBuyer];
    }
}
