<?php

namespace App\Services\Courier;

use App\Models\IntegrationConnection;
use RuntimeException;

class SamedayAwbService
{
    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function createAwb(IntegrationConnection $connection, array $input): array
    {
        if (! $connection->isSameday() || ! $connection->is_active) {
            throw new RuntimeException('Conexiunea selectată nu este Sameday activă.');
        }

        $sameday = $this->newSamedayInstance($connection);
        $pickupPointId = $this->resolvePickupPointId(
            $sameday,
            $input['pickup_point_id'] ?? data_get($connection->settings, 'pickup_point_id')
        );
        $serviceId = $this->resolveServiceId(
            $sameday,
            $input['service_id'] ?? data_get($connection->settings, 'default_service_id')
        );

        $packageCount = max(1, (int) ($input['package_count'] ?? 1));
        $packageWeightKg = max(0.01, (float) ($input['package_weight_kg'] ?? 1));
        $cashOnDelivery = max(0, (float) ($input['cod_amount'] ?? 0));
        $insuredValue = max(0, (float) ($input['insured_value'] ?? 0));

        $packageTypeClass = '\\Sameday\\Objects\\Types\\PackageType';
        $awbPaymentTypeClass = '\\Sameday\\Objects\\Types\\AwbPaymentType';
        $parcelDimensionsClass = '\\Sameday\\Objects\\ParcelDimensionsObject';
        $recipientClass = '\\Sameday\\Objects\\PostAwb\\Request\\AwbRecipientEntityObject';
        $postAwbRequestClass = '\\Sameday\\Requests\\SamedayPostAwbRequest';

        $packageType = new $packageTypeClass(constant($packageTypeClass.'::PARCEL'));
        $awbPaymentType = new $awbPaymentTypeClass(constant($awbPaymentTypeClass.'::CLIENT'));

        $parcels = [];
        for ($index = 0; $index < $packageCount; $index++) {
            $parcels[] = new $parcelDimensionsClass($packageWeightKg);
        }

        $recipient = new $recipientClass(
            trim((string) ($input['recipient_city'] ?? '')),
            trim((string) ($input['recipient_county'] ?? '')),
            trim((string) ($input['recipient_address'] ?? '')),
            trim((string) ($input['recipient_name'] ?? '')),
            trim((string) ($input['recipient_phone'] ?? '')),
            trim((string) ($input['recipient_email'] ?? '')),
            null,
            filled($input['recipient_postal_code'] ?? null) ? trim((string) $input['recipient_postal_code']) : null
        );

        $request = new $postAwbRequestClass(
            $pickupPointId,
            null,
            $packageType,
            $parcels,
            $serviceId,
            $awbPaymentType,
            $recipient,
            $insuredValue,
            $cashOnDelivery,
            null,
            null,
            [],
            null,
            filled($input['reference'] ?? null) ? trim((string) $input['reference']) : null,
            filled($input['observation'] ?? null) ? trim((string) $input['observation']) : null,
            null,
            null,
            null,
            null,
            null,
            null,
            'RON'
        );

        $response = $sameday->postAwb($request);

        $rawResponseBody = '';
        if (is_object($response) && method_exists($response, 'getRawResponse')) {
            $rawResponse = $response->getRawResponse();
            if (is_object($rawResponse) && method_exists($rawResponse, 'getBody')) {
                $rawResponseBody = (string) $rawResponse->getBody();
            }
        }

        $decodedResponse = json_decode($rawResponseBody, true);
        if (! is_array($decodedResponse)) {
            $decodedResponse = ['raw_body' => $rawResponseBody];
        }

        return [
            'awb_number' => (string) $response->getAwbNumber(),
            'shipping_cost' => method_exists($response, 'getCost') ? (float) $response->getCost() : null,
            'pickup_point_id' => $pickupPointId,
            'service_id' => $serviceId,
            'request_payload' => [
                'pickup_point_id' => $pickupPointId,
                'service_id' => $serviceId,
                'recipient_name' => trim((string) ($input['recipient_name'] ?? '')),
                'recipient_phone' => trim((string) ($input['recipient_phone'] ?? '')),
                'recipient_email' => filled($input['recipient_email'] ?? null) ? trim((string) $input['recipient_email']) : null,
                'recipient_county' => trim((string) ($input['recipient_county'] ?? '')),
                'recipient_city' => trim((string) ($input['recipient_city'] ?? '')),
                'recipient_address' => trim((string) ($input['recipient_address'] ?? '')),
                'recipient_postal_code' => filled($input['recipient_postal_code'] ?? null) ? trim((string) $input['recipient_postal_code']) : null,
                'package_count' => $packageCount,
                'package_weight_kg' => $packageWeightKg,
                'cod_amount' => $cashOnDelivery,
                'insured_value' => $insuredValue,
                'reference' => filled($input['reference'] ?? null) ? trim((string) $input['reference']) : null,
                'observation' => filled($input['observation'] ?? null) ? trim((string) $input['observation']) : null,
            ],
            'response_payload' => $decodedResponse,
        ];
    }

    /**
     * Estimates AWB cost without creating shipment.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function estimateAwbCost(IntegrationConnection $connection, array $input): array
    {
        if (! $connection->isSameday() || ! $connection->is_active) {
            throw new RuntimeException('Conexiunea selectată nu este Sameday activă.');
        }

        $sameday = $this->newSamedayInstance($connection);
        $pickupPointId = $this->resolvePickupPointId(
            $sameday,
            $input['pickup_point_id'] ?? data_get($connection->settings, 'pickup_point_id')
        );
        $serviceId = $this->resolveServiceId(
            $sameday,
            $input['service_id'] ?? data_get($connection->settings, 'default_service_id')
        );

        $packageCount = max(1, (int) ($input['package_count'] ?? 1));
        $packageWeightKg = max(0.01, (float) ($input['package_weight_kg'] ?? 1));
        $cashOnDelivery = max(0, (float) ($input['cod_amount'] ?? 0));
        $insuredValue = max(0, (float) ($input['insured_value'] ?? 0));

        $recipientName = $this->requireFilledString($input, 'recipient_name', 'Nume destinatar');
        $recipientPhone = $this->requireFilledString($input, 'recipient_phone', 'Telefon destinatar');
        $recipientCounty = $this->requireFilledString($input, 'recipient_county', 'Județ');
        $recipientCity = $this->requireFilledString($input, 'recipient_city', 'Oraș');
        $recipientAddress = $this->requireFilledString($input, 'recipient_address', 'Adresă');
        $recipientEmail = filled($input['recipient_email'] ?? null) ? trim((string) $input['recipient_email']) : '';
        $recipientPostalCode = filled($input['recipient_postal_code'] ?? null) ? trim((string) $input['recipient_postal_code']) : null;

        $packageTypeClass = '\\Sameday\\Objects\\Types\\PackageType';
        $awbPaymentTypeClass = '\\Sameday\\Objects\\Types\\AwbPaymentType';
        $parcelDimensionsClass = '\\Sameday\\Objects\\ParcelDimensionsObject';
        $recipientClass = '\\Sameday\\Objects\\PostAwb\\Request\\AwbRecipientEntityObject';
        $estimateRequestClass = '\\Sameday\\Requests\\SamedayPostAwbEstimationRequest';

        $packageType = new $packageTypeClass(constant($packageTypeClass.'::PARCEL'));
        $awbPaymentType = new $awbPaymentTypeClass(constant($awbPaymentTypeClass.'::CLIENT'));

        $parcels = [];
        for ($index = 0; $index < $packageCount; $index++) {
            $parcels[] = new $parcelDimensionsClass($packageWeightKg);
        }

        $recipient = new $recipientClass(
            $recipientCity,
            $recipientCounty,
            $recipientAddress,
            $recipientName,
            $recipientPhone,
            $recipientEmail,
            null,
            $recipientPostalCode
        );

        $request = new $estimateRequestClass(
            $pickupPointId,
            null,
            $packageType,
            $parcels,
            $serviceId,
            $awbPaymentType,
            $recipient,
            $insuredValue,
            $cashOnDelivery,
            null,
            [],
            'RON'
        );

        $response = $sameday->postAwbEstimation($request);
        $estimatedCost = method_exists($response, 'getCost') ? (float) $response->getCost() : null;
        $currency = method_exists($response, 'getCurrency') ? (string) $response->getCurrency() : 'RON';
        $deliveryTimeSeconds = method_exists($response, 'getTime') ? (int) $response->getTime() : null;

        if ($estimatedCost === null) {
            throw new RuntimeException('Sameday nu a returnat un cost estimat.');
        }

        return [
            'cost' => $estimatedCost,
            'currency' => $currency !== '' ? $currency : 'RON',
            'delivery_time_seconds' => $deliveryTimeSeconds,
            'pickup_point_id' => $pickupPointId,
            'service_id' => $serviceId,
        ];
    }

    /**
     * @return array<int, string>
     */
    public function getPickupPointOptions(IntegrationConnection $connection): array
    {
        $sameday = $this->newSamedayInstance($connection);
        $requestClass = '\\Sameday\\Requests\\SamedayGetPickupPointsRequest';

        $request = new $requestClass();
        if (method_exists($request, 'setCountPerPage')) {
            $request->setCountPerPage(100);
        }

        $response = $sameday->getPickupPoints($request);
        $options = [];

        foreach ($response->getPickupPoints() as $pickupPoint) {
            $pickupPointId = (int) $pickupPoint->getId();
            $city = '';
            if (method_exists($pickupPoint, 'getCity')) {
                $cityObject = $pickupPoint->getCity();
                if (is_object($cityObject) && method_exists($cityObject, 'getName')) {
                    $city = (string) $cityObject->getName();
                }
            }
            $alias = method_exists($pickupPoint, 'getAlias') ? (string) $pickupPoint->getAlias() : '';

            $parts = array_filter([$alias, $city, (string) $pickupPoint->getAddress()]);
            $label = implode(' - ', $parts);
            if (method_exists($pickupPoint, 'isDefault') && $pickupPoint->isDefault()) {
                $label .= ' (default)';
            }

            $options[$pickupPointId] = $label !== '' ? $label : "Pickup #{$pickupPointId}";
        }

        return $options;
    }

    /**
     * @return array<int, string>
     */
    public function getServiceOptions(IntegrationConnection $connection): array
    {
        $sameday = $this->newSamedayInstance($connection);
        $requestClass = '\\Sameday\\Requests\\SamedayGetServicesRequest';

        $request = new $requestClass();
        if (method_exists($request, 'setCountPerPage')) {
            $request->setCountPerPage(100);
        }

        $response = $sameday->getServices($request);
        $options = [];

        foreach ($response->getServices() as $service) {
            $serviceId = (int) $service->getId();
            $label = (string) $service->getName();

            if (method_exists($service, 'getCode') && $service->getCode() !== '') {
                $label .= ' ['.$service->getCode().']';
            }

            if (method_exists($service, 'isDefault') && $service->isDefault()) {
                $label .= ' (default)';
            }

            $options[$serviceId] = $label;
        }

        return $options;
    }

    private function resolvePickupPointId(object $sameday, mixed $explicitPickupPointId): int
    {
        $explicitPickupPointId = (int) $explicitPickupPointId;
        if ($explicitPickupPointId > 0) {
            return $explicitPickupPointId;
        }

        $requestClass = '\\Sameday\\Requests\\SamedayGetPickupPointsRequest';
        $request = new $requestClass();
        if (method_exists($request, 'setCountPerPage')) {
            $request->setCountPerPage(100);
        }

        $response = $sameday->getPickupPoints($request);
        $pickupPoints = $response->getPickupPoints();

        if (! is_array($pickupPoints) || $pickupPoints === []) {
            throw new RuntimeException('Nu există pickup points disponibile în contul Sameday.');
        }

        foreach ($pickupPoints as $pickupPoint) {
            if (method_exists($pickupPoint, 'isDefault') && $pickupPoint->isDefault()) {
                return (int) $pickupPoint->getId();
            }
        }

        return (int) $pickupPoints[0]->getId();
    }

    private function resolveServiceId(object $sameday, mixed $explicitServiceId): int
    {
        $explicitServiceId = (int) $explicitServiceId;
        if ($explicitServiceId > 0) {
            return $explicitServiceId;
        }

        $requestClass = '\\Sameday\\Requests\\SamedayGetServicesRequest';
        $request = new $requestClass();
        if (method_exists($request, 'setCountPerPage')) {
            $request->setCountPerPage(100);
        }

        $response = $sameday->getServices($request);
        $services = $response->getServices();

        if (! is_array($services) || $services === []) {
            throw new RuntimeException('Nu există servicii disponibile în contul Sameday.');
        }

        foreach ($services as $service) {
            if (method_exists($service, 'isDefault') && $service->isDefault()) {
                return (int) $service->getId();
            }
        }

        return (int) $services[0]->getId();
    }

    private function newSamedayInstance(IntegrationConnection $connection): object
    {
        if (! $connection->isSameday()) {
            throw new RuntimeException('Conexiunea selectată nu este Sameday.');
        }

        if ($connection->samedayUsername() === '' || $connection->samedayPassword() === '') {
            throw new RuntimeException('Conexiunea Sameday nu are username/parolă configurate.');
        }

        $requiredClasses = [
            '\\Sameday\\SamedayClient',
            '\\Sameday\\Sameday',
            '\\Sameday\\Requests\\SamedayGetPickupPointsRequest',
            '\\Sameday\\Requests\\SamedayGetServicesRequest',
            '\\Sameday\\Requests\\SamedayPostAwbRequest',
            '\\Sameday\\Requests\\SamedayPostAwbEstimationRequest',
            '\\Sameday\\Objects\\ParcelDimensionsObject',
            '\\Sameday\\Objects\\Types\\PackageType',
            '\\Sameday\\Objects\\Types\\AwbPaymentType',
            '\\Sameday\\Objects\\PostAwb\\Request\\AwbRecipientEntityObject',
        ];

        foreach ($requiredClasses as $class) {
            if (! class_exists($class)) {
                throw new RuntimeException('Sameday SDK lipsește. Rulează: composer require sameday-courier/php-sdk');
            }
        }

        $clientClass = '\\Sameday\\SamedayClient';
        $samedayClass = '\\Sameday\\Sameday';

        $client = new $clientClass(
            $connection->samedayUsername(),
            $connection->samedayPassword(),
            $connection->samedayApiUrl(),
            'mal-erp',
            '1.0'
        );

        return new $samedayClass($client);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function requireFilledString(array $input, string $key, string $label): string
    {
        $value = trim((string) ($input[$key] ?? ''));

        if ($value === '') {
            throw new RuntimeException("Completează câmpul „{$label}” pentru estimarea costului.");
        }

        return $value;
    }
}
