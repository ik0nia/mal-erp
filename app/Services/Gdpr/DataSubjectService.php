<?php

namespace App\Services\Gdpr;

use App\Models\Customer;
use App\Models\CustomerDeliveryAddress;
use App\Models\EmailMessage;
use App\Models\SamedayAwb;
use App\Models\SupplierContact;
use App\Models\User;
use App\Models\WooOrder;

/**
 * Localizează datele cu caracter personal ale unei persoane vizate (după email/telefon)
 * în toate tabelele ERP. STRICT READ-ONLY. Ignoră scope-urile de locație (GDPR = căutare globală).
 */
class DataSubjectService
{
    public function isEmail(string $id): bool
    {
        return filter_var($id, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * @return array<string,mixed> structură cu toate înregistrările găsite, grupate pe tabel
     */
    public function locate(string $identifier): array
    {
        $identifier = trim($identifier);
        $email = $this->isEmail($identifier) ? mb_strtolower($identifier) : null;
        $phone = $email ? null : preg_replace('/\D+/', '', $identifier);
        $phoneLike = $phone && strlen($phone) >= 6 ? '%'.substr($phone, -9).'%' : null;

        // protecție: fără criteriu valid NU căutăm nimic (să nu returnăm toată baza)
        if (! $email && ! $phoneLike) {
            return [
                'identifier' => $identifier, 'searched_by' => 'necunoscut',
                'generated_at' => now()->toDateTimeString(),
                'records' => array_fill_keys([
                    'customers', 'delivery_addresses', 'online_orders', 'shipping_awbs',
                    'emails', 'supplier_contacts', 'users',
                ], []),
            ];
        }

        return [
            'identifier' => $identifier,
            'searched_by' => $email ? 'email' : ($phoneLike ? 'telefon' : 'necunoscut'),
            'generated_at' => now()->toDateTimeString(),
            'records' => [
                'customers' => $this->customers($email, $phoneLike),
                'delivery_addresses' => $this->deliveryAddresses($phoneLike),
                'online_orders' => $this->orders($email, $phoneLike),
                'shipping_awbs' => $this->awbs($email, $phoneLike),
                'emails' => $this->emails($email),
                'supplier_contacts' => $this->supplierContacts($email, $phoneLike),
                'users' => $this->users($email),
            ],
        ];
    }

    /** Numărul total de înregistrări găsite. */
    public function countRecords(array $located): int
    {
        return collect($located['records'])->sum(fn ($rows) => count($rows));
    }

    private function customers(?string $email, ?string $phoneLike): array
    {
        return Customer::withoutGlobalScopes()
            ->where(function ($q) use ($email, $phoneLike) {
                if ($email) {
                    $q->orWhereRaw('LOWER(email) = ?', [$email]);
                }
                if ($phoneLike) {
                    $q->orWhere('phone', 'like', $phoneLike);
                }
            })
            ->get()->map->toArray()->all();
    }

    private function deliveryAddresses(?string $phoneLike): array
    {
        if (! $phoneLike) {
            return [];
        }

        return CustomerDeliveryAddress::where('contact_phone', 'like', $phoneLike)
            ->get()->map->toArray()->all();
    }

    private function orders(?string $email, ?string $phoneLike): array
    {
        return WooOrder::withoutGlobalScopes()
            ->where(function ($q) use ($email, $phoneLike) {
                if ($email) {
                    $q->orWhereRaw('LOWER(JSON_UNQUOTE(JSON_EXTRACT(billing, "$.email"))) = ?', [$email]);
                }
                if ($phoneLike) {
                    $q->orWhere('billing->phone', 'like', $phoneLike);
                }
            })
            ->get()
            ->map(fn (WooOrder $o) => [
                'id' => $o->id, 'number' => $o->number, 'status' => $o->status,
                'order_date' => $o->order_date, 'total' => $o->total,
                'billing' => $o->billing, 'shipping' => $o->shipping, 'customer_note' => $o->customer_note,
            ])->all();
    }

    private function awbs(?string $email, ?string $phoneLike): array
    {
        return SamedayAwb::where(function ($q) use ($email, $phoneLike) {
            if ($email) {
                $q->orWhereRaw('LOWER(recipient_email) = ?', [$email]);
            }
            if ($phoneLike) {
                $q->orWhere('recipient_phone', 'like', $phoneLike);
            }
        })
            ->get()
            ->map(fn (SamedayAwb $a) => [
                'id' => $a->id, 'awb_number' => $a->awb_number, 'status' => $a->status,
                'recipient_name' => $a->recipient_name, 'recipient_phone' => $a->recipient_phone,
                'recipient_email' => $a->recipient_email, 'recipient_address' => $a->recipient_address,
                'recipient_city' => $a->recipient_city, 'created_at' => $a->created_at?->toDateTimeString(),
            ])->all();
    }

    private function emails(?string $email): array
    {
        if (! $email) {
            return [];
        }

        return EmailMessage::whereRaw('LOWER(from_email) = ?', [$email])
            ->orWhereRaw('LOWER(JSON_UNQUOTE(to_recipients)) LIKE ?', ['%'.$email.'%'])
            ->get()
            ->map(fn (EmailMessage $m) => [
                'id' => $m->id, 'subject' => $m->subject, 'from_email' => $m->from_email,
                'from_name' => $m->from_name, 'sent_at' => $m->sent_at?->toDateTimeString(),
            ])->all();
    }

    private function supplierContacts(?string $email, ?string $phoneLike): array
    {
        return SupplierContact::where(function ($q) use ($email, $phoneLike) {
            if ($email) {
                $q->orWhereRaw('LOWER(email) = ?', [$email]);
            }
            if ($phoneLike) {
                $q->orWhere('phone', 'like', $phoneLike);
            }
        })->get()->map->toArray()->all();
    }

    private function users(?string $email): array
    {
        if (! $email) {
            return [];
        }

        return User::whereRaw('LOWER(email) = ?', [$email])
            ->get()
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email])
            ->all();
    }
}
