<?php

namespace App\Filament\App\Resources\SamedayAwbResource\Pages;

use App\Filament\App\Resources\SamedayAwbResource;
use App\Models\IntegrationConnection;
use App\Models\SamedayAwb;
use App\Models\User;
use App\Services\Courier\SamedayAwbService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Throwable;

class CreateSamedayAwb extends CreateRecord
{
    protected static string $resource = SamedayAwbResource::class;

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    protected function handleRecordCreation(array $data): Model
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            throw ValidationException::withMessages([
                'location_id' => 'Trebuie să fii autentificat pentru a crea un AWB.',
            ]);
        }

        $locationId = (int) ($data['location_id'] ?? 0);
        if ($locationId <= 0) {
            $locationId = (int) ($user->location_id ?? 0);
        }

        if ($locationId <= 0) {
            throw ValidationException::withMessages([
                'location_id' => 'Nu a fost selectată o locație validă.',
            ]);
        }

        $connection = SamedayAwbResource::resolveSamedayConnectionForLocation($locationId);
        if (! $connection instanceof IntegrationConnection) {
            throw ValidationException::withMessages([
                'location_id' => 'Locația selectată nu are conexiune Sameday activă.',
            ]);
        }

        try {
            $result = app(SamedayAwbService::class)->createAwb($connection, $data);

            return SamedayAwb::query()->create([
                'location_id' => $locationId,
                'user_id' => (int) $user->id,
                'integration_connection_id' => (int) $connection->id,
                'provider' => IntegrationConnection::PROVIDER_SAMEDAY,
                'status' => SamedayAwb::STATUS_CREATED,
                'awb_number' => (string) ($result['awb_number'] ?? ''),
                'service_id' => isset($result['service_id']) ? (int) $result['service_id'] : null,
                'pickup_point_id' => isset($result['pickup_point_id']) ? (int) $result['pickup_point_id'] : null,
                'recipient_name' => trim((string) ($data['recipient_name'] ?? '')),
                'recipient_phone' => trim((string) ($data['recipient_phone'] ?? '')),
                'recipient_email' => filled($data['recipient_email'] ?? null) ? trim((string) $data['recipient_email']) : null,
                'recipient_county' => trim((string) ($data['recipient_county'] ?? '')),
                'recipient_city' => trim((string) ($data['recipient_city'] ?? '')),
                'recipient_address' => trim((string) ($data['recipient_address'] ?? '')),
                'recipient_postal_code' => filled($data['recipient_postal_code'] ?? null) ? trim((string) $data['recipient_postal_code']) : null,
                'package_count' => max(1, (int) ($data['package_count'] ?? 1)),
                'package_weight_kg' => max(0.01, (float) ($data['package_weight_kg'] ?? 1)),
                'cod_amount' => max(0, (float) ($data['cod_amount'] ?? 0)),
                'insured_value' => max(0, (float) ($data['insured_value'] ?? 0)),
                'shipping_cost' => isset($result['shipping_cost']) ? (float) $result['shipping_cost'] : null,
                'reference' => filled($data['reference'] ?? null) ? trim((string) $data['reference']) : null,
                'observation' => filled($data['observation'] ?? null) ? trim((string) $data['observation']) : null,
                'request_payload' => $result['request_payload'] ?? null,
                'response_payload' => $result['response_payload'] ?? null,
                'error_message' => null,
            ]);
        } catch (Throwable $exception) {
            SamedayAwb::query()->create([
                'location_id' => $locationId,
                'user_id' => (int) $user->id,
                'integration_connection_id' => (int) $connection->id,
                'provider' => IntegrationConnection::PROVIDER_SAMEDAY,
                'status' => SamedayAwb::STATUS_FAILED,
                'awb_number' => null,
                'service_id' => isset($data['service_id']) ? (int) $data['service_id'] : null,
                'pickup_point_id' => isset($data['pickup_point_id']) ? (int) $data['pickup_point_id'] : null,
                'recipient_name' => trim((string) ($data['recipient_name'] ?? '')),
                'recipient_phone' => trim((string) ($data['recipient_phone'] ?? '')),
                'recipient_email' => filled($data['recipient_email'] ?? null) ? trim((string) $data['recipient_email']) : null,
                'recipient_county' => trim((string) ($data['recipient_county'] ?? '')),
                'recipient_city' => trim((string) ($data['recipient_city'] ?? '')),
                'recipient_address' => trim((string) ($data['recipient_address'] ?? '')),
                'recipient_postal_code' => filled($data['recipient_postal_code'] ?? null) ? trim((string) $data['recipient_postal_code']) : null,
                'package_count' => max(1, (int) ($data['package_count'] ?? 1)),
                'package_weight_kg' => max(0.01, (float) ($data['package_weight_kg'] ?? 1)),
                'cod_amount' => max(0, (float) ($data['cod_amount'] ?? 0)),
                'insured_value' => max(0, (float) ($data['insured_value'] ?? 0)),
                'shipping_cost' => null,
                'reference' => filled($data['reference'] ?? null) ? trim((string) $data['reference']) : null,
                'observation' => filled($data['observation'] ?? null) ? trim((string) $data['observation']) : null,
                'request_payload' => $data,
                'response_payload' => null,
                'error_message' => $exception->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'recipient_name' => 'Nu s-a putut crea AWB: '.$exception->getMessage(),
            ]);
        }
    }
}
