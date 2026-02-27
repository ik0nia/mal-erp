<?php

namespace App\Filament\App\Resources\SamedayAwbResource\Pages;

use App\Filament\App\Resources\SamedayAwbResource;
use App\Models\IntegrationConnection;
use App\Models\SamedayAwb;
use App\Models\User;
use App\Models\WooOrder;
use App\Services\Courier\SamedayAwbService;
use App\Services\WooCommerce\WooClient;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Throwable;

class CreateSamedayAwb extends CreateRecord
{
    protected static string $resource = SamedayAwbResource::class;

    /** @var int|null Woo order ID passed via query string */
    protected ?int $wooOrderId = null;

    public function mount(): void
    {
        parent::mount();

        $this->wooOrderId = request()->integer('woo_order_id') ?: null;

        $prefill = array_filter([
            'recipient_name'        => request()->string('recipient_name')->toString(),
            'recipient_phone'       => request()->string('recipient_phone')->toString(),
            'recipient_email'       => request()->string('recipient_email')->toString(),
            'recipient_address'     => request()->string('recipient_address')->toString(),
            'recipient_postal_code' => request()->string('recipient_postal_code')->toString(),
            'cod_amount'            => request()->string('cod_amount')->toString() ?: null,
            'reference'             => request()->string('reference')->toString(),
        ]);

        if (! empty($prefill)) {
            $this->form->fill($prefill);
        }
    }

    /**
     * @return array<int, Action>
     */
    protected function getFormActions(): array
    {
        return [
            Action::make('estimate_cost')
                ->label('Verifică cost')
                ->icon('heroicon-o-calculator')
                ->color('gray')
                ->action(function (): void {
                    $state = $this->form->getState();

                    $user = auth()->user();
                    if (! $user instanceof User) {
                        Notification::make()
                            ->warning()
                            ->title('Estimare nereușită')
                            ->body('Trebuie să fii autentificat pentru estimare.')
                            ->send();

                        return;
                    }

                    $locationId = (int) ($user->location_id ?? 0);
                    if ($locationId <= 0) {
                        Notification::make()
                            ->warning()
                            ->title('Estimare nereușită')
                            ->body('Utilizatorul curent nu are locație asociată.')
                            ->send();

                        return;
                    }

                    $connection = SamedayAwbResource::resolveSamedayConnectionForLocation($locationId);
                    if (! $connection instanceof IntegrationConnection) {
                        Notification::make()
                            ->warning()
                            ->title('Estimare nereușită')
                            ->body('Locația ta nu are conexiune Sameday activă.')
                            ->send();

                        return;
                    }

                    try {
                        $estimate = app(SamedayAwbService::class)->estimateAwbCost($connection, $state);
                        $amount = number_format((float) ($estimate['cost'] ?? 0), 2);
                        $currency = (string) ($estimate['currency'] ?? 'RON');
                        $timeText = '';
                        $seconds = (int) ($estimate['delivery_time_seconds'] ?? 0);
                        if ($seconds > 0) {
                            $timeText = ' | Timp estimat: '.gmdate('H:i:s', $seconds);
                        }

                        Notification::make()
                            ->success()
                            ->title('Estimare cost Sameday')
                            ->body("Cost estimat: {$amount} {$currency}{$timeText}")
                            ->send();
                    } catch (Throwable $exception) {
                        Notification::make()
                            ->warning()
                            ->title('Estimare nereușită')
                            ->body($exception->getMessage())
                            ->send();
                    }
                }),
            ...parent::getFormActions(),
        ];
    }

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
                'recipient_name' => 'Trebuie să fii autentificat pentru a crea un AWB.',
            ]);
        }

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

            return SamedayAwb::query()->create([
                'location_id' => $locationId,
                'user_id' => (int) $user->id,
                'integration_connection_id' => (int) $connection->id,
                'woo_order_id' => $this->wooOrderId,
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
                'response_payload' => null,
                'error_message' => $exception->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'recipient_name' => 'Nu s-a putut crea AWB: '.$exception->getMessage(),
            ]);
        }
    }

    protected function afterCreate(): void
    {
        /** @var SamedayAwb $awb */
        $awb = $this->record;

        if (! $this->wooOrderId || ! filled($awb->awb_number)) {
            return;
        }

        $order = WooOrder::find($this->wooOrderId);
        if (! $order instanceof WooOrder || ! $order->connection) {
            return;
        }

        try {
            $client = new WooClient($order->connection);
            $client->addOrderNote((int) $order->woo_id, 'AWB Sameday: '.$awb->awb_number);
            $client->updateOrderMeta((int) $order->woo_id, '_sameday_awb_number', (string) $awb->awb_number);
        } catch (Throwable) {
            // Non-critical: don't block AWB creation if WooCommerce push fails
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
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
