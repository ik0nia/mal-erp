<?php

namespace App\Services\WooCommerce;

use App\Models\User;
use App\Models\WooOrder;
use App\Models\WooOrderEdit;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Comasarea (merge) mai multor comenzi WooCommerce ale aceluiași client la aceeași
 * destinație, într-o singură comandă. Varianta A: se păstrează comanda cea mai veche
 * ca „principală", produsele din celelalte se mută în ea, iar acele comenzi se anulează
 * (status «cancelled») cu notă de audit în Woo + WooOrderEdit în ERP.
 *
 * Refolosește primitivele existente (WooClient::updateOrder / updateOrderStatus /
 * addOrderNote și WooOrderSyncService::upsertOrder). NU face nimic până nu e apelat.
 */
class WooOrderMergeService
{
    /** Statusuri în care o comandă poate fi comasată (identice cu editarea din ViewWooOrder). */
    public const MERGEABLE_STATUSES = ['pending', 'processing', 'on-hold'];

    /**
     * Validează un set de comenzi pentru comasare. Nu modifică nimic.
     *
     * @param  Collection<int, WooOrder>  $orders
     * @return array{ok: bool, errors: array<int, string>, warnings: array<int, string>, target: ?WooOrder, secondaries: array<int, WooOrder>}
     */
    public function validate(Collection $orders): array
    {
        $errors   = [];
        $warnings = [];
        $orders   = $orders->values();

        if ($orders->count() < 2) {
            return $this->result(false, ['Selectează cel puțin 2 comenzi pentru comasare.'], $warnings, null, []);
        }

        // Toate pe aceeași conexiune (același magazin Woo)
        if ($orders->pluck('connection_id')->unique()->count() > 1) {
            $errors[] = 'Comenzile sunt din magazine diferite (conexiuni Woo diferite).';
        }

        // Fiecare comandă: are woo_id, e editabilă, nu e deja facturată
        foreach ($orders as $o) {
            if (! $o->woo_id) {
                $errors[] = "Comanda #{$o->number} nu are corespondent în WooCommerce.";
            }
            if (! in_array((string) $o->status, self::MERGEABLE_STATUSES, true)) {
                $label = WooOrder::STATUS_LABELS[$o->status] ?? $o->status;
                $errors[] = "Comanda #{$o->number} are status «{$label}» — se comasează doar comenzi în așteptare / procesare / on-hold.";
            }
            if ($o->winmentor_sync_status === 'synced') {
                $errors[] = "Comanda #{$o->number} e deja trimisă în WinMentor (facturată) — nu se mai poate comasa.";
            }
        }

        // Același client (email de facturare, normalizat)
        $emails = $orders->map(fn (WooOrder $o) => $this->normEmail($o));
        if ($emails->contains('') || $emails->unique()->count() !== 1) {
            $errors[] = 'Comenzile nu au același client (email de facturare diferit sau lipsă).';
        }

        // Aceeași destinație de livrare
        if ($orders->map(fn (WooOrder $o) => $this->normDestination($o))->unique()->count() !== 1) {
            $errors[] = 'Comenzile au adrese de livrare diferite.';
        }

        // ── Avertismente (nu blochează) ──
        if ($orders->map(fn (WooOrder $o) => $this->normPhone($o))->unique()->count() > 1) {
            $warnings[] = 'Telefoanele diferă între comenzi.';
        }
        if ($orders->pluck('payment_method')->unique()->count() > 1) {
            $warnings[] = 'Metode de plată diferite — verifică rambursul înainte.';
        }
        foreach ($orders as $o) {
            $hasAwb = $o->samedayAwbs->contains(fn ($a) => filled($a->awb_number) && $a->status !== 'cancelled');
            if ($hasAwb) {
                $warnings[] = "Comanda #{$o->number} are deja AWB Sameday — anulează-l manual dacă o comasezi.";
            }
        }

        if (! empty($errors)) {
            return $this->result(false, $errors, $warnings, null, []);
        }

        // Target = cea mai veche (order_date, apoi id). Cheie sortabilă ca string zero-padded.
        $sorted = $orders
            ->sortBy(fn (WooOrder $o) => sprintf('%011d-%010d', optional($o->order_date)->timestamp ?? 0, $o->id))
            ->values();

        return $this->result(true, [], $warnings, $sorted->first(), $sorted->slice(1)->values()->all());
    }

    /**
     * Execută comasarea. Mută liniile din secundare în target, anulează secundarele,
     * adaugă note de audit în Woo + WooOrderEdit, apoi resincronizează local din Woo.
     *
     * @param  array<int, WooOrder>|Collection<int, WooOrder>  $secondaries
     * @param  float|null  $shippingNet  Cost transport NET pentru comanda comasată (ex. estimare Sameday
     *                                    pe greutatea nouă). Dacă e dat și target-ul are linie de transport,
     *                                    o suprascrie. null = lasă transportul comenzii principale neschimbat.
     * @return array{ok: bool, message: string, moved: int, cancelled: int, shipping_set: bool}
     */
    public function merge(WooOrder $target, array|Collection $secondaries, ?User $user = null, ?float $shippingNet = null): array
    {
        $secondaries = collect($secondaries)->values();
        $userLabel   = $user?->email ?? $user?->name ?? 'ERP';

        // Re-validez ca să nu se execute pe un set devenit invalid între timp.
        $check = $this->validate(collect([$target])->merge($secondaries));
        if (! $check['ok']) {
            return ['ok' => false, 'message' => 'Validare eșuată: '.implode(' ', $check['errors']), 'moved' => 0, 'cancelled' => 0, 'shipping_set' => false];
        }
        // Forțez target-ul re-calculat (cea mai veche) ca sursă de adevăr.
        $target      = $check['target'];
        $secondaries = collect($check['secondaries']);

        $client = new WooClient($target->connection);
        $sync   = new WooOrderSyncService();

        $beforeSnapshot = [
            'target'  => ['number' => $target->number, 'items' => $this->itemsSnapshot($target)],
            'sources' => $secondaries->map(fn (WooOrder $o) => ['number' => $o->number, 'woo_id' => $o->woo_id])->all(),
        ];

        $moved         = 0;
        $cancelledCount = 0;
        $sourceNumbers = [];

        foreach ($secondaries as $sec) {
            // 1. Mut fiecare linie în target.
            foreach ($sec->items as $item) {
                if (! $item->woo_product_id) {
                    continue;
                }

                $target->load('items');
                $existing = $target->items->firstWhere('woo_product_id', $item->woo_product_id);

                if ($existing && $existing->woo_item_id) {
                    // Produs deja în target → cresc cantitatea liniei existente (Woo recalculează).
                    $newQty = (int) $existing->quantity + (int) $item->quantity;
                    $client->updateOrder((int) $target->woo_id, [
                        'line_items' => [['id' => (int) $existing->woo_item_id, 'quantity' => $newQty]],
                    ]);
                } else {
                    // Produs nou → adaug linie, păstrând prețul agreat de client.
                    $line = [
                        'product_id' => (int) $item->woo_product_id,
                        'quantity'   => (int) $item->quantity,
                    ];
                    if ($item->subtotal !== null && $item->total !== null) {
                        $line['subtotal'] = number_format((float) $item->subtotal, 2, '.', '');
                        $line['total']    = number_format((float) $item->total, 2, '.', '');
                    }
                    $client->updateOrder((int) $target->woo_id, ['line_items' => [$line]]);
                }

                $moved++;

                // Reîmprospătez target-ul local ca următoarea iterație să vadă cantitatea corectă.
                $raw = $client->getOrder((int) $target->woo_id);
                if (! empty($raw)) {
                    $sync->upsertOrder($target->connection_id, $target->location_id, $raw);
                    $target = $target->fresh();
                }
            }

            // 2. Anulez secundara + notă de trasabilitate.
            $client->addOrderNote(
                (int) $sec->woo_id,
                "Comasată în comanda #{$target->number} (ERP · {$userLabel}). Produsele au fost mutate acolo.",
                false
            );
            $client->updateOrderStatus((int) $sec->woo_id, 'cancelled');

            $rawSec = $client->getOrder((int) $sec->woo_id);
            if (! empty($rawSec)) {
                $sync->upsertOrder($sec->connection_id, $sec->location_id, $rawSec);
            }

            WooOrderEdit::create([
                'woo_order_id' => $sec->id,
                'user_email'   => $userLabel,
                'action'       => 'merged_into',
                'label'        => "Comasată în #{$target->number}",
                'before'       => ['status' => $sec->getOriginal('status') ?? $sec->status],
                'after'        => ['status' => 'cancelled', 'target' => $target->number],
            ]);

            $cancelledCount++;
            $sourceNumbers[] = '#'.$sec->number;
        }

        // 3. Transport: îl setez pe greutatea comasată (Woo NU-l recalculează singur la update de line_items).
        $shippingSet = false;
        if ($shippingNet !== null && $shippingNet >= 0) {
            $target   = $target->fresh() ?? $target;
            $shipLine = collect($target->data['shipping_lines'] ?? [])->first();

            if ($shipLine && isset($shipLine['id'])) {
                $oldNet = (float) $target->shipping_total;
                $client->updateOrder((int) $target->woo_id, [
                    'shipping_lines' => [[
                        'id'    => $shipLine['id'],
                        'total' => number_format(round($shippingNet, 2), 2, '.', ''),
                    ]],
                ]);

                $rawShip = $client->getOrder((int) $target->woo_id);
                if (! empty($rawShip)) {
                    $sync->upsertOrder($target->connection_id, $target->location_id, $rawShip);
                    $target = $target->fresh() ?? $target;
                }

                WooOrderEdit::create([
                    'woo_order_id' => $target->id,
                    'user_email'   => $userLabel,
                    'action'       => 'edit_transport',
                    'label'        => 'Transport recalculat la comasare: '.number_format($oldNet, 2).' → '.number_format($shippingNet, 2).' lei (net)',
                    'before'       => ['shipping_total' => number_format($oldNet, 2, '.', '')],
                    'after'        => ['shipping_total' => number_format(round($shippingNet, 2), 2, '.', '')],
                ]);

                $shippingSet = true;
            }
        }

        // Notă + audit pe target.
        $srcList = implode(', ', $sourceNumbers);
        $shipNote = $shippingSet ? ' Transport recalculat pe greutatea comasată ('.number_format(round((float) $shippingNet, 2), 2).' lei net).' : '';
        $client->addOrderNote((int) $target->woo_id, "Comasare ERP · {$userLabel}: preluate produsele din {$srcList}.{$shipNote}", false);

        WooOrderEdit::create([
            'woo_order_id' => $target->id,
            'user_email'   => $userLabel,
            'action'       => 'merge',
            'label'        => "Comasat: {$srcList} → această comandă",
            'before'       => $beforeSnapshot,
            'after'        => ['items' => $this->itemsSnapshot($target->fresh() ?? $target)],
        ]);

        // Resync final target din Woo.
        $rawFinal = $client->getOrder((int) $target->woo_id);
        if (! empty($rawFinal)) {
            $sync->upsertOrder($target->connection_id, $target->location_id, $rawFinal);
        }

        $shipMsg = $shippingSet
            ? ' Transport setat la '.number_format(round((float) $shippingNet, 2), 2).' lei net.'
            : ($shippingNet !== null ? ' Transportul NU a putut fi setat automat — ajustează-l manual.' : '');

        return [
            'ok'           => true,
            'message'      => "Comasat: {$srcList} → #{$target->number}. {$moved} linii mutate, {$cancelledCount} comandă/comenzi anulate.{$shipMsg}",
            'moved'        => $moved,
            'cancelled'    => $cancelledCount,
            'shipping_set' => $shippingSet,
        ];
    }

    /**
     * Construiește o previzualizare a comenzii rezultate (produse + cantități cumulate),
     * fără să modifice nimic. Folosită în popup-ul de confirmare.
     *
     * Transportul NU e inclus ca sumă fixă: se recalculează pe greutatea totală
     * (de aici `merged_weight_kg` + `source_shipping` de referință). Estimarea efectivă
     * o face stratul UI prin Sameday, best-effort.
     *
     * @param  Collection<int, WooOrder>  $orders
     * @return array{ok: bool, errors: array<int, string>, warnings: array<int, string>, target: ?WooOrder, secondaries: array<int, WooOrder>, lines: array<int, array{woo_product_id: int, name: string, qty: int, line_total: float, is_new: bool}>, products_total: float, merged_weight_kg: float, missing_weight: array<int, string>, source_shipping: array<int, array{number: string, shipping: float}>}
     */
    public function preview(Collection $orders): array
    {
        $check = $this->validate($orders);

        if (! $check['ok']) {
            return $check + [
                'lines' => [], 'products_total' => 0.0,
                'merged_weight_kg' => 0.0, 'missing_weight' => [], 'source_shipping' => [],
            ];
        }

        /** @var WooOrder $target */
        $target      = $check['target'];
        $secondaries = collect($check['secondaries']);

        // Pornim de la liniile target-ului, cheie = woo_product_id.
        $lines = [];
        foreach ($target->items as $it) {
            if (! $it->woo_product_id) {
                continue;
            }
            $lines[(int) $it->woo_product_id] = [
                'woo_product_id' => (int) $it->woo_product_id,
                'name'           => $it->name,
                'qty'            => (int) $it->quantity,
                'line_total'     => (float) $it->total,
                'is_new'         => false,
            ];
        }

        // Adăugăm liniile din secundare (cumulăm cantitatea + totalul dacă produsul există deja).
        foreach ($secondaries as $sec) {
            foreach ($sec->items as $it) {
                if (! $it->woo_product_id) {
                    continue;
                }
                $key = (int) $it->woo_product_id;
                if (isset($lines[$key])) {
                    $lines[$key]['qty']        += (int) $it->quantity;
                    $lines[$key]['line_total'] += (float) $it->total;
                } else {
                    $lines[$key] = [
                        'woo_product_id' => $key,
                        'name'           => $it->name,
                        'qty'            => (int) $it->quantity,
                        'line_total'     => (float) $it->total,
                        'is_new'         => true,
                    ];
                }
            }
        }

        $lines = array_values($lines);

        // Greutatea totală a coletului comasat (din greutățile produselor × cantitate).
        $weight  = 0.0;
        $missing = [];
        foreach ($lines as $l) {
            $product = \App\Models\WooProduct::where('woo_id', $l['woo_product_id'])->first(['data']);
            $w = (float) data_get($product?->data, 'weight', 0);
            if ($w > 0) {
                $weight += $w * $l['qty'];
            } else {
                $missing[] = $l['name'];
            }
        }

        $sourceShipping = collect([$target])->merge($secondaries)
            ->map(fn (WooOrder $o) => ['number' => $o->number, 'shipping' => (float) $o->shipping_total])
            ->all();

        return $check + [
            'lines'            => $lines,
            'products_total'   => round(array_sum(array_column($lines, 'line_total')), 2),
            'merged_weight_kg' => round($weight, 2),
            'missing_weight'   => $missing,
            'source_shipping'  => $sourceShipping,
        ];
    }

    private function normEmail(WooOrder $o): string
    {
        return strtolower(trim((string) data_get($o->billing, 'email', '')));
    }

    private function normPhone(WooOrder $o): string
    {
        $digits = preg_replace('/\D+/', '', (string) data_get($o->billing, 'phone', ''));

        return substr($digits, -9);
    }

    /** Cheie normalizată de destinație: livrare dacă există, altfel facturare. */
    private function normDestination(WooOrder $o): string
    {
        $ship = filled(data_get($o->shipping, 'address_1')) ? $o->shipping : $o->billing;

        $raw = implode('|', [
            data_get($ship, 'address_1', ''),
            data_get($ship, 'address_2', ''),
            data_get($ship, 'city', ''),
            data_get($ship, 'postcode', ''),
        ]);

        return (string) Str::of($raw)->lower()->ascii()->replaceMatches('/\s+/', ' ')->trim();
    }

    /** @return array<int, array<string, mixed>> */
    private function itemsSnapshot(WooOrder $o): array
    {
        return $o->items->map(fn ($it) => [
            'woo_item_id'    => (int) $it->woo_item_id,
            'woo_product_id' => (int) $it->woo_product_id,
            'name'           => $it->name,
            'quantity'       => (int) $it->quantity,
            'total'          => (string) $it->total,
        ])->all();
    }

    /**
     * @param  array<int, string>          $errors
     * @param  array<int, string>          $warnings
     * @param  array<int, WooOrder>        $secondaries
     * @return array{ok: bool, errors: array<int, string>, warnings: array<int, string>, target: ?WooOrder, secondaries: array<int, WooOrder>}
     */
    private function result(bool $ok, array $errors, array $warnings, ?WooOrder $target, array $secondaries): array
    {
        return compact('ok', 'errors', 'warnings', 'target', 'secondaries');
    }
}
