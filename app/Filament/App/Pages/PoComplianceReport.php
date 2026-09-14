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

    /** Începutul folosirii sistemului = luna primei recepții PO (dinamic, cache 1h). */
    public static function systemStart(): \Carbon\Carbon
    {
        return \Illuminate\Support\Facades\Cache::remember('po_report_system_start', 3600, function () {
            $first = PurchaseOrder::whereNotNull('received_at')->min('received_at')
                ?? PurchaseOrder::min('created_at');
            return $first ? \Carbon\Carbon::parse($first)->startOfMonth() : now()->startOfMonth();
        });
    }

    /** KPI-uri de ansamblu — DOAR de când se folosește sistemul. */
    public function overview(): array
    {
        $start   = self::systemStart();
        $startStr = $start->format('Y-m-d');

        // Cantitativ: recepții WinMentor vs PO recepționate.
        // nr_receptie NU e unic global (se repetă între furnizori) → numărăm documentul real: (nr_receptie, part_id).
        $receptii   = (int) DB::table('winmentor_intrari_raw')
            ->whereRaw('STR_TO_DATE(CONCAT(an,"-",LPAD(luna,2,"0"),"-01"), "%Y-%m-%d") >= ?', [$startStr])
            ->where('nr_receptie', '!=', '')->whereNotNull('nr_receptie')
            ->selectRaw('COUNT(DISTINCT nr_receptie, part_id) c')->value('c');
        $poReceived = PurchaseOrder::where('status', 'received')->where('received_at', '>=', $startStr)->count();
        $pctFaraCount = $receptii > 0 ? (int) round((1 - min($poReceived, $receptii) / $receptii) * 100) : 0;

        // Valoric
        $intrariVal = (float) DB::table('winmentor_intrari_raw')
            ->whereRaw('STR_TO_DATE(CONCAT(an,"-",LPAD(luna,2,"0"),"-01"), "%Y-%m-%d") >= ?', [$startStr])
            ->selectRaw('SUM(cantitate*pret) v')->value('v');
        $poVal     = (float) PurchaseOrder::where('status', 'received')->where('received_at', '>=', $startStr)->sum('total_value');
        $pctFaraVal = $intrariVal > 0 ? (int) round((1 - min($poVal, $intrariVal) / $intrariVal) * 100) : 0;

        $anomalii = static::baseQuery()->count();

        return compact('start', 'receptii', 'poReceived', 'pctFaraCount', 'intrariVal', 'poVal', 'pctFaraVal', 'anomalii');
    }

    /** Achiziții (intrări WinMentor) vs PO-uri, pe lună — DOAR de la startul sistemului. */
    public function monthlyWithoutPo(): array
    {
        $start = self::systemStart();
        $intrari = DB::table('winmentor_intrari_raw')
            ->where('nr_receptie', '!=', '')->whereNotNull('nr_receptie')
            ->selectRaw('an, luna, COUNT(DISTINCT nr_receptie, part_id) receptii, SUM(cantitate*pret) val')
            ->groupBy('an', 'luna')->get()
            ->keyBy(fn ($r) => sprintf('%04d-%02d', $r->an, $r->luna));
        $pos = PurchaseOrder::where('status', 'received')->whereNotNull('received_at')
            ->selectRaw('YEAR(received_at) an, MONTH(received_at) luna, COUNT(*) n, SUM(total_value) val')
            ->groupBy('an', 'luna')->get()
            ->keyBy(fn ($r) => sprintf('%04d-%02d', $r->an, $r->luna));

        $out = [];
        $cursor = $start->copy();
        $end = now()->startOfMonth();
        while ($cursor->lte($end)) {
            $ym = $cursor->format('Y-m');
            $iVal = (float) ($intrari[$ym]->val ?? 0);
            $pVal = (float) ($pos[$ym]->val ?? 0);
            $rec  = (int) ($intrari[$ym]->receptii ?? 0);
            $poN  = (int) ($pos[$ym]->n ?? 0);
            $out[] = [
                'ym'            => $ym,
                'label'         => $cursor->locale('ro')->isoFormat('MMM YY'),
                'intrari'       => $iVal,
                'po'            => $pVal,
                'receptii'      => $rec,
                'po_n'          => $poN,
                'pct_fara'      => $iVal > 0 ? (int) round((1 - min($pVal, $iVal) / $iVal) * 100) : 0,
                'pct_fara_count'=> $rec > 0 ? (int) round((1 - min($poN, $rec) / $rec) * 100) : 0,
            ];
            $cursor->addMonth();
        }
        return $out;
    }

    /**
     * Hărți pentru rezolvarea furnizorului real din intrările WinMentor:
     *  - wmToSup:  winmentor_id → supplier_id (legătura principală, part_id = winmentor_id)
     *  - nameToSup: prefix nume (10 car.) → supplier_id (fallback când part_id nu prinde)
     *  - name:     supplier_id → denumire oficială din lista de furnizori
     *  - buyers:   supplier_id → responsabili (pivot supplier_buyers, pot fi mai mulți)
     */
    public static function supplierResolution(): array
    {
        return \Illuminate\Support\Facades\Cache::remember('po_report_supplier_resolution', 600, function () {
            $sups = DB::table('suppliers')->select('id', 'name', 'winmentor_id')->get();
            $wmToSup = $nameToSup = $name = [];
            foreach ($sups as $s) {
                if ($s->winmentor_id !== null && $s->winmentor_id !== '') {
                    $wmToSup[ltrim(trim((string) $s->winmentor_id), '0')] = $s->id;
                }
                if (($k = self::nameKey($s->name)) !== '') {
                    $nameToSup[$k] ??= $s->id;
                }
                $name[$s->id] = $s->name;
            }

            // denToSup: dacă ORICE part_id al unei denumiri se leagă la un furnizor (prin
            // winmentor_id), atribuim aceeași denumire integral acolo — unifică variantele
            // secundare de part_id (coduri WinMentor padded) fără potrivire fragilă pe nume.
            $denToSup = [];
            DB::table('winmentor_intrari_raw')->select('part_id', 'den_furnizor')
                ->whereNotNull('den_furnizor')->where('den_furnizor', '!=', '')->distinct()->get()
                ->each(function ($r) use (&$denToSup, $wmToSup) {
                    $pid = ltrim(trim((string) $r->part_id), '0');
                    if ($pid !== '' && isset($wmToSup[$pid])) {
                        $denToSup[trim($r->den_furnizor)] ??= $wmToSup[$pid];
                    }
                });

            // Responsabili: întâi din pivotul supplier_buyers (sursa oficială)…
            $buyers = [];
            DB::table('supplier_buyers as sb')->join('users as u', 'u.id', '=', 'sb.user_id')
                ->select('sb.supplier_id', 'u.name')->orderBy('u.name')->get()
                ->each(function ($r) use (&$buyers) { $buyers[$r->supplier_id][] = $r->name; });
            // …iar unde pivotul e gol, deducem din cine face PO-urile (ex. Toya → Teo)
            $poBuyers = [];
            DB::table('purchase_orders as p')->join('users as u', 'u.id', '=', 'p.buyer_id')
                ->selectRaw('p.supplier_id, u.name, COUNT(*) n')->groupBy('p.supplier_id', 'u.name')
                ->orderByDesc('n')->get()
                ->each(function ($r) use (&$poBuyers) { $poBuyers[$r->supplier_id][] = $r->name; });
            foreach ($poBuyers as $sid => $names) {
                $buyers[$sid] ??= $names;
            }

            return compact('wmToSup', 'nameToSup', 'denToSup', 'name', 'buyers');
        });
    }

    /** Rezolvă un rând de intrare (part_id + den_furnizor) la un supplier_id din lista noastră. */
    private static function resolveSupplierId(array $r, array $res): ?int
    {
        $pid = ltrim(trim((string) ($r['part_id'] ?? '')), '0');
        if ($pid !== '' && isset($res['wmToSup'][$pid])) {
            return $res['wmToSup'][$pid];
        }
        $den = trim((string) ($r['den_furnizor'] ?? ''));
        return $res['denToSup'][$den] ?? $res['nameToSup'][self::nameKey($den)] ?? null;
    }

    /** Cheie de nume normalizată (fără spații/punctuație, nume complet) pentru potrivire fără coliziuni. */
    private static function nameKey(?string $name): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $name));
    }

    /** PO-uri recepționate per supplier_id de la start (opțional pe lună: ym => sid => n). */
    private static function poCounts(string $startStr, bool $byMonth = false): array
    {
        $q = DB::table('purchase_orders')->where('status', 'received')->where('received_at', '>=', $startStr)
            ->whereNotNull('supplier_id');
        if ($byMonth) {
            $rows = $q->selectRaw('YEAR(received_at) an, MONTH(received_at) luna, supplier_id, COUNT(*) n')
                ->groupBy('an', 'luna', 'supplier_id')->get();
            $map = [];
            foreach ($rows as $r) {
                $map[sprintf('%04d-%02d', $r->an, $r->luna)][$r->supplier_id] = (int) $r->n;
            }
            return $map;
        }
        return $q->selectRaw('supplier_id, COUNT(*) n')->groupBy('supplier_id')
            ->pluck('n', 'supplier_id')->all();
    }

    /** Top furnizori la care se cumpără fără PO (cele mai multe recepții fără procedură). */
    public function topSuppliersWithoutPo(int $limit = 12): array
    {
        $start = self::systemStart()->format('Y-m-d');
        $res   = self::supplierResolution();
        $poBySup = self::poCounts($start);

        $intr = DB::table('winmentor_intrari_raw')
            ->whereRaw('STR_TO_DATE(CONCAT(an,"-",LPAD(luna,2,"0"),"-01"),"%Y-%m-%d") >= ?', [$start])
            ->whereNotNull('den_furnizor')->where('den_furnizor', '!=', '')
            ->where('nr_receptie', '!=', '')->whereNotNull('nr_receptie')
            ->selectRaw('part_id, den_furnizor, COUNT(DISTINCT nr_receptie) receptii')
            ->groupBy('part_id', 'den_furnizor')->get();

        // Agregă pe supplier_id (unifică variantele de part_id ale aceluiași furnizor)
        $agg = [];
        foreach ($intr as $r) {
            $sid = self::resolveSupplierId((array) $r, $res);
            $key = $sid !== null ? 's' . $sid : 'n:' . $r->den_furnizor;
            $agg[$key] ??= [
                'sid'      => $sid,
                'furnizor' => $sid !== null ? ($res['name'][$sid] ?? $r->den_furnizor) : $r->den_furnizor,
                'receptii' => 0,
            ];
            $agg[$key]['receptii'] += (int) $r->receptii;
        }

        $out = [];
        foreach ($agg as $a) {
            if ($a['receptii'] < 3) {
                continue;
            }
            $po = $a['sid'] !== null ? (int) ($poBySup[$a['sid']] ?? 0) : 0;
            $faraPo = max($a['receptii'] - $po, 0);
            $out[] = [
                'furnizor'    => $a['furnizor'],
                'responsabil' => $a['sid'] !== null ? implode(', ', $res['buyers'][$a['sid']] ?? []) : '',
                'receptii'    => $a['receptii'],
                'po'          => min($po, $a['receptii']),
                'fara_po'     => $faraPo,
                'pct'         => (int) round($faraPo / $a['receptii'] * 100),
            ];
        }
        usort($out, fn ($a, $b) => $b['fara_po'] <=> $a['fara_po']);
        return array_slice($out, 0, $limit);
    }

    /**
     * Detaliere lunară pe furnizor: câte recepții (documente de intrare) nu au PO,
     * și câte sunt „suspecte" (marfa a ajuns fără să se fi făcut comandă în prealabil).
     * @return array<int, array{ym:string,label:string,receptii:int,fara_po:int,suppliers:array}>
     */
    public function monthlySupplierBreakdown(): array
    {
        $start = self::systemStart();
        $startStr = $start->format('Y-m-d');
        $res = self::supplierResolution();
        $poMap = self::poCounts($startStr, byMonth: true);

        $intr = DB::table('winmentor_intrari_raw')
            ->whereRaw('STR_TO_DATE(CONCAT(an,"-",LPAD(luna,2,"0"),"-01"),"%Y-%m-%d") >= ?', [$startStr])
            ->whereNotNull('den_furnizor')->where('den_furnizor', '!=', '')
            ->where('nr_receptie', '!=', '')->whereNotNull('nr_receptie')
            ->selectRaw('an, luna, part_id, den_furnizor, COUNT(DISTINCT nr_receptie) receptii')
            ->groupBy('an', 'luna', 'part_id', 'den_furnizor')->get();

        // Agregă pe (lună, supplier_id)
        $months = [];
        foreach ($intr as $r) {
            $ym  = sprintf('%04d-%02d', $r->an, $r->luna);
            $sid = self::resolveSupplierId((array) $r, $res);
            $key = $sid !== null ? 's' . $sid : 'n:' . $r->den_furnizor;
            $months[$ym][$key] ??= [
                'sid'      => $sid,
                'furnizor' => $sid !== null ? ($res['name'][$sid] ?? $r->den_furnizor) : $r->den_furnizor,
                'receptii' => 0,
            ];
            $months[$ym][$key]['receptii'] += (int) $r->receptii;
        }

        $out = [];
        foreach ($months as $ym => $sups) {
            $rows = [];
            foreach ($sups as $a) {
                $po = $a['sid'] !== null ? (int) ($poMap[$ym][$a['sid']] ?? 0) : 0;
                $fara = max($a['receptii'] - $po, 0);
                $rows[] = [
                    'furnizor'    => $a['furnizor'],
                    'responsabil' => $a['sid'] !== null ? implode(', ', $res['buyers'][$a['sid']] ?? []) : '',
                    'receptii'    => $a['receptii'],
                    'po'          => min($po, $a['receptii']),
                    'fara_po'     => $fara,
                ];
            }
            usort($rows, fn ($x, $y) => $y['fara_po'] <=> $x['fara_po']);
            $out[] = [
                'ym'        => $ym,
                'label'     => \Carbon\Carbon::createFromFormat('Y-m-d', $ym . '-01')->locale('ro')->isoFormat('MMMM YYYY'),
                'receptii'  => array_sum(array_column($rows, 'receptii')),
                'fara_po'   => array_sum(array_column($rows, 'fara_po')),
                'suppliers' => array_values(array_filter($rows, fn ($s) => $s['fara_po'] > 0)),
            ];
        }
        usort($out, fn ($a, $b) => strcmp($b['ym'], $a['ym']));
        return $out;
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
