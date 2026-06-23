<?php

namespace App\Services\Gdpr;

use Illuminate\Support\Facades\DB;

/**
 * Ștergere GDPR („dreptul de a fi uitat") — ANONIMIZEAZĂ datele personale ale unei persoane
 * vizate, PĂSTRÂND înregistrările (integritate contabilă/FK). NU șterge rânduri.
 * Operează DOAR pe înregistrările deja localizate (după ID).
 */
class ErasureService
{
    private const REDACTED = '[ȘTERS GDPR]';

    private const ADDRESS_PII = ['first_name', 'last_name', 'email', 'phone', 'address_1', 'address_2', 'company'];

    private function redactAddress(?array $addr): ?array
    {
        if (! is_array($addr)) {
            return $addr;
        }
        foreach (self::ADDRESS_PII as $f) {
            if (array_key_exists($f, $addr)) {
                $addr[$f] = in_array($f, ['email', 'phone'], true) ? null : self::REDACTED;
            }
        }

        return $addr;
    }

    /** @return array<string,int> sumar număr înregistrări per tabel */
    public function erase(array $located, bool $dryRun): array
    {
        $rec = $located['records'];
        $ids = fn (string $key) => collect($rec[$key] ?? [])->pluck('id')->filter()->all();

        return [
            'customers' => $this->bulk('customers', $ids('customers'), $dryRun, [
                'name' => self::REDACTED, 'representative_name' => null, 'email' => null,
                'phone' => null, 'address' => self::REDACTED, 'city' => null, 'county' => null,
                'postal_code' => null, 'notes' => null,
            ]),
            'delivery_addresses' => $this->bulk('customer_delivery_addresses', $ids('delivery_addresses'), $dryRun, [
                'contact_name' => self::REDACTED, 'contact_phone' => null, 'address' => self::REDACTED,
            ]),
            'supplier_contacts' => $this->bulk('supplier_contacts', $ids('supplier_contacts'), $dryRun, [
                'name' => self::REDACTED, 'email' => null, 'phone' => null,
            ]),
            'shipping_awbs' => $this->bulk('sameday_awbs', $ids('shipping_awbs'), $dryRun, [
                'recipient_name' => self::REDACTED, 'recipient_phone' => null, 'recipient_email' => null,
                'recipient_address' => self::REDACTED, 'recipient_postal_code' => null,
                'request_payload' => null, 'response_payload' => null, 'anonymized_at' => now(),
            ]),
            'emails' => $this->bulk('email_messages', $ids('emails'), $dryRun, [
                'from_name' => self::REDACTED, 'from_email' => '[sters]', 'to_recipients' => null,
                'cc_recipients' => null, 'body_html' => null, 'body_text' => self::REDACTED,
                'attachments' => null, 'internal_notes' => null, 'anonymized_at' => now(),
            ]),
            'online_orders' => $this->eraseOrders($ids('online_orders'), $dryRun),
            // users: NU se anonimizează automat (conturi de personal) — se tratează manual
        ];
    }

    private function bulk(string $table, array $ids, bool $dryRun, array $values): int
    {
        if (empty($ids)) {
            return 0;
        }
        if (! $dryRun) {
            DB::table($table)->whereIn('id', $ids)->update($values);
        }

        return count($ids);
    }

    private function eraseOrders(array $ids, bool $dryRun): int
    {
        if (empty($ids)) {
            return 0;
        }
        if ($dryRun) {
            return count($ids);
        }

        DB::table('woo_orders')->whereIn('id', $ids)->orderBy('id')->chunkById(200, function ($rows) {
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
                    'billing' => json_encode($this->redactAddress(json_decode($r->billing ?? 'null', true)), JSON_UNESCAPED_UNICODE),
                    'shipping' => json_encode($this->redactAddress(json_decode($r->shipping ?? 'null', true)), JSON_UNESCAPED_UNICODE),
                    'customer_note' => null,
                    'data' => is_array($data) ? json_encode($data, JSON_UNESCAPED_UNICODE) : $r->data,
                    'anonymized_at' => now(),
                ]);
            }
        });

        return count($ids);
    }
}
