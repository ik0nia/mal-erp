<?php

namespace App\Services\Courier;

use App\Filament\App\Resources\SamedayAwbResource;
use App\Models\IntegrationConnection;
use App\Models\SamedayAwb;
use App\Models\User;
use App\Models\WooOrder;
use App\Services\WooCommerce\WooClient;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Fluxul complet de creare AWB Sameday — sursă unică folosită de pagina de
 * creare ȘI de popup-ul „Creare AWB" de pe comanda WooCommerce:
 * validare context → creare la Sameday → persistare (inclusiv eșecuri) →
 * oglindire pe site (wp_sameday_awb) → notă + meta pe comanda Woo.
 */
class SamedayAwbCreator
{
    /**
     * @param  array<string, mixed>  $data  starea formularului de AWB
     *
     * @throws ValidationException
     */
    public function create(array $data, User $user, ?WooOrder $order = null): SamedayAwb
    {
        $locationId = (int) ($user->location_id ?? 0);
        if ($locationId <= 0) {
            throw ValidationException::withMessages([
                'recipient_name' => 'Utilizatorul curent nu are o locație validă.',
            ]);
        }

        $connection = SamedayAwbResource::resolveSamedayConnectionForLocation($locationId);
        if (! $connection instanceof IntegrationConnection) {
            throw ValidationException::withMessages([
                'recipient_name' => 'Locația ta nu are conexiune Sameday activă.',
            ]);
        }

        $fallbackCounty = SamedayAwbResource::countyNameForCurrentUserLocation((int) ($data['recipient_county_id'] ?? 0))
            ?? trim((string) ($data['recipient_county'] ?? ''));
        $fallbackCity = SamedayAwbResource::cityNameForCurrentUserLocation(
            (int) ($data['recipient_county_id'] ?? 0),
            (int) ($data['recipient_city_id'] ?? 0)
        ) ?? trim((string) ($data['recipient_city'] ?? ''));
        $fallbackAddress = $this->composeAddressFromData($data);

        try {
            $result = app(SamedayAwbService::class)->createAwb($connection, $data);

            $resolvedPackageCount = max(
                1,
                (int) data_get($result, 'request_payload.package_count', max(1, (int) ($data['package_count'] ?? 1)))
            );
            $resolvedPackageWeight = max(
                0.01,
                (float) data_get($result, 'request_payload.package_weight_kg', max(0.01, (float) ($data['package_weight_kg'] ?? 1)))
            );

            $awb = SamedayAwb::query()->create([
                'location_id' => $locationId,
                'user_id' => (int) $user->id,
                'integration_connection_id' => (int) $connection->id,
                'woo_order_id' => $order?->id,
                'provider' => IntegrationConnection::PROVIDER_SAMEDAY,
                'status' => SamedayAwb::STATUS_CREATED,
                'awb_number' => (string) ($result['awb_number'] ?? ''),
                'service_id' => isset($result['service_id']) ? (int) $result['service_id'] : null,
                'pickup_point_id' => isset($result['pickup_point_id']) ? (int) $result['pickup_point_id'] : null,
                'recipient_name' => trim((string) ($data['recipient_name'] ?? '')),
                'recipient_phone' => trim((string) ($data['recipient_phone'] ?? '')),
                'recipient_email' => filled($data['recipient_email'] ?? null) ? trim((string) $data['recipient_email']) : null,
                'recipient_county' => trim((string) data_get($result, 'request_payload.recipient_county', $fallbackCounty)),
                'recipient_city' => trim((string) data_get($result, 'request_payload.recipient_city', $fallbackCity)),
                'recipient_address' => trim((string) data_get($result, 'request_payload.recipient_address', $fallbackAddress)),
                'recipient_postal_code' => filled($data['recipient_postal_code'] ?? null) ? trim((string) $data['recipient_postal_code']) : null,
                'package_count' => $resolvedPackageCount,
                'package_weight_kg' => $resolvedPackageWeight,
                'cod_amount' => max(0, (float) ($data['cod_amount'] ?? 0)),
                'insured_value' => max(0, (float) ($data['insured_value'] ?? 0)),
                'shipping_cost' => isset($result['shipping_cost']) ? (float) $result['shipping_cost'] : null,
                'reference' => filled($data['reference'] ?? null) ? trim((string) $data['reference']) : null,
                'observation' => filled($data['observation'] ?? null) ? trim((string) $data['observation']) : null,
                'request_payload' => $result['request_payload'] ?? null,
                'response_payload' => $result['response_payload'] ?? null,
                'error_message' => null,
            ]);

            $this->afterSuccessfulCreate($awb, $order);

            return $awb;
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $badRequest = $exception instanceof \Sameday\Exceptions\SamedayBadRequestException ? $exception : null;
            $errorMessage = $badRequest ? $this->translateSamedayErrors($badRequest, $data) : $exception->getMessage();
            SamedayAwb::query()->create([
                'location_id' => $locationId,
                'user_id' => (int) $user->id,
                'integration_connection_id' => (int) $connection->id,
                'woo_order_id' => $order?->id,
                'provider' => IntegrationConnection::PROVIDER_SAMEDAY,
                'status' => SamedayAwb::STATUS_FAILED,
                'awb_number' => null,
                'service_id' => isset($data['service_id']) ? (int) $data['service_id'] : null,
                'pickup_point_id' => isset($data['pickup_point_id']) ? (int) $data['pickup_point_id'] : null,
                'recipient_name' => trim((string) ($data['recipient_name'] ?? '')),
                'recipient_phone' => trim((string) ($data['recipient_phone'] ?? '')),
                'recipient_email' => filled($data['recipient_email'] ?? null) ? trim((string) $data['recipient_email']) : null,
                'recipient_county' => $fallbackCounty,
                'recipient_city' => $fallbackCity,
                'recipient_address' => $fallbackAddress,
                'recipient_postal_code' => filled($data['recipient_postal_code'] ?? null) ? trim((string) $data['recipient_postal_code']) : null,
                'package_count' => max(1, (int) ($data['package_count'] ?? 1)),
                'package_weight_kg' => max(0.01, (float) ($data['package_weight_kg'] ?? 1)),
                'cod_amount' => max(0, (float) ($data['cod_amount'] ?? 0)),
                'insured_value' => max(0, (float) ($data['insured_value'] ?? 0)),
                'shipping_cost' => null,
                'reference' => filled($data['reference'] ?? null) ? trim((string) $data['reference']) : null,
                'observation' => filled($data['observation'] ?? null) ? trim((string) $data['observation']) : null,
                'request_payload' => $data,
                'response_payload' => $badRequest ? ['errors' => $badRequest->getErrors()] : null,
                'error_message' => $errorMessage,
            ]);

            throw ValidationException::withMessages([
                'recipient_name' => $badRequest ? $errorMessage : 'Nu s-a putut crea AWB: '.$errorMessage,
            ]);
        }
    }

    /**
     * Traduce erorile de validare Sameday (per câmp) în mesaje utile în română.
     *
     * @param  array<string, mixed>  $data
     */
    private function translateSamedayErrors(\Sameday\Exceptions\SamedayBadRequestException $exception, array $data): string
    {
        $fields = [];
        foreach ($exception->getErrors() as $error) {
            foreach ((array) ($error['key'] ?? []) as $key) {
                $fields[] = (string) $key;
            }
        }
        $fields = array_unique($fields);

        // Cel mai frecvent: căsuța Easybox aleasă de client nu e în catalogul contului
        // (harta publică Sameday din checkout arată TOATE lockerele din țară, dar contul
        // poate genera AWB doar către cele din nomenclatorul propriu).
        if (in_array('lockerLastMile', $fields, true) || in_array('oohLastMile', $fields, true)) {
            $lockerId = (int) ($data['locker_last_mile'] ?? 0);
            $city = trim((string) ($data['recipient_city'] ?? ''));

            $suggestions = '';
            $cityId = (int) ($data['recipient_city_id'] ?? 0);
            $cityName = $cityId > 0
                ? SamedayAwbResource::cityNameForCurrentUserLocation((int) ($data['recipient_county_id'] ?? 0), $cityId)
                : $city;
            if ($cityName) {
                $nearby = \Illuminate\Support\Facades\DB::table('sameday_lockers')
                    ->where('city', 'like', '%'.$cityName.'%')
                    ->limit(3)->pluck('name')->all();
                if ($nearby) {
                    $suggestions = ' În '.$cityName.' ai disponibile: '.implode('; ', $nearby).'.';
                }
            }

            return "Căsuța Easybox #{$lockerId} nu este în catalogul contului Sameday, deși apare pe harta publică din checkout. "
                .'Alege altă căsuță validă de pe hartă'.$suggestions
                .' Sau golește câmpul „Căsuță Easybox" pentru livrare la adresă.';
        }

        $labels = [
            'awbRecipient.name'        => 'nume destinatar',
            'awbRecipient.phoneNumber' => 'telefon destinatar',
            'awbRecipient.address'     => 'adresă destinatar',
            'awbRecipient.cityString'  => 'oraș destinatar',
            'awbRecipient.county'      => 'județ destinatar',
            'awbRecipient.postalCode'  => 'cod poștal',
            'parcels'                  => 'colet (greutate/dimensiuni)',
            'service'                  => 'serviciu Sameday',
            'pickupPoint'              => 'punct de ridicare',
        ];

        $human = array_map(fn ($f) => $labels[$f] ?? $f, $fields);

        return $human
            ? 'Sameday a respins AWB-ul — verifică: '.implode(', ', $human).'.'
            : 'Sameday a respins AWB-ul (date invalide). Verifică datele destinatarului și coletul.';
    }

    /** Oglindire pe site + notă și meta pe comanda WooCommerce. */
    private function afterSuccessfulCreate(SamedayAwb $awb, ?WooOrder $order): void
    {
        app(SamedayAwbSiteMirror::class)->push($awb);

        if (! $order instanceof WooOrder || ! $order->connection || ! filled($awb->awb_number)) {
            return;
        }

        try {
            $client = new WooClient($order->connection);
            $client->addOrderNote((int) $order->woo_id, 'AWB Sameday: '.$awb->awb_number);
            $client->updateOrderMeta((int) $order->woo_id, '_sameday_awb_number', (string) $awb->awb_number);
        } catch (Throwable) {
            // Non-critic: AWB-ul există; nota/meta pe Woo nu blochează fluxul
        }
    }

    private function composeAddressFromData(array $data): string
    {
        $explicit = trim((string) ($data['recipient_address'] ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }

        $street = trim((string) ($data['recipient_street'] ?? ''));
        if ($street === '') {
            return '';
        }

        $parts = ["Str. {$street}"];

        foreach ([
            'recipient_street_no' => 'Nr.',
            'recipient_block' => 'Bl.',
            'recipient_staircase' => 'Sc.',
            'recipient_floor' => 'Et.',
            'recipient_apartment' => 'Ap.',
        ] as $field => $label) {
            $value = trim((string) ($data[$field] ?? ''));
            if ($value !== '') {
                $parts[] = "{$label} {$value}";
            }
        }

        return implode(', ', $parts);
    }
}
