<?php

namespace App\Services\Gdpr;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Politici de retenție GDPR — ANONIMIZEAZĂ datele cu caracter personal din înregistrările
 * vechi, PĂSTRÂND datele de business/contabile (nr. document, totaluri, date). NU șterge rânduri.
 */
class RetentionService
{
    /** Câmpurile de adresă redactate într-un bloc billing/shipping WooCommerce. */
    private const ADDRESS_PII = ['first_name', 'last_name', 'email', 'phone', 'address_1', 'address_2', 'company'];

    private function redactAddress(?array $addr): ?array
    {
        if (! is_array($addr)) {
            return $addr;
        }
        foreach (self::ADDRESS_PII as $f) {
            if (array_key_exists($f, $addr)) {
                $addr[$f] = in_array($f, ['email', 'phone'], true) ? null : '[ANONIMIZAT]';
            }
        }

        return $addr; // păstrează city, state, postcode, country (date agregate)
    }

    public function anonymizeSamedayAwbs(Carbon $cutoff, bool $dryRun): int
    {
        $q = DB::table('sameday_awbs')->where('created_at', '<', $cutoff)->whereNull('anonymized_at');
        $count = (clone $q)->count();
        if (! $dryRun && $count > 0) {
            $q->update([
                'recipient_name'        => '[ANONIMIZAT]',
                'recipient_phone'       => null,
                'recipient_email'       => null,
                'recipient_address'     => '[ANONIMIZAT]',
                'recipient_postal_code' => null,
                'request_payload'       => null,
                'response_payload'      => null,
                'anonymized_at'         => now(),
            ]);
        }

        return $count;
    }

    public function anonymizeWooOrders(Carbon $cutoff, bool $dryRun): int
    {
        $base = DB::table('woo_orders')->where('created_at', '<', $cutoff)->whereNull('anonymized_at');
        $count = (clone $base)->count();
        if ($dryRun || $count === 0) {
            return $count;
        }

        (clone $base)->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $r) {
                $data = json_decode($r->data ?? 'null', true);
                if (is_array($data)) {
                    if (isset($data['billing'])) {
                        $data['billing'] = $this->redactAddress($data['billing']);
                    }
                    if (isset($data['shipping'])) {
                        $data['shipping'] = $this->redactAddress($data['shipping']);
                    }
                }

                DB::table('woo_orders')->where('id', $r->id)->update([
                    'billing'       => json_encode($this->redactAddress(json_decode($r->billing ?? 'null', true)), JSON_UNESCAPED_UNICODE),
                    'shipping'      => json_encode($this->redactAddress(json_decode($r->shipping ?? 'null', true)), JSON_UNESCAPED_UNICODE),
                    'customer_note' => null,
                    'data'          => is_array($data) ? json_encode($data, JSON_UNESCAPED_UNICODE) : $r->data,
                    'anonymized_at' => now(),
                ]);
            }
        });

        return $count;
    }

    public function anonymizeEmailMessages(Carbon $cutoff, bool $dryRun): int
    {
        $q = DB::table('email_messages')->where('created_at', '<', $cutoff)->whereNull('anonymized_at');
        $count = (clone $q)->count();
        if (! $dryRun && $count > 0) {
            $q->update([
                'from_name'      => '[ANONIMIZAT]',
                'from_email'     => '[anonimizat]',
                'to_recipients'  => null,
                'cc_recipients'  => null,
                'body_html'      => null,
                'body_text'      => '[conținut anonimizat conform politicii de retenție]',
                'attachments'    => null,
                'internal_notes' => null,
                'anonymized_at'  => now(),
            ]);
        }

        return $count;
    }
}
