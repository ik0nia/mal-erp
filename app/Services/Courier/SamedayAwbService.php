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
        $contactPersonId = $this->nullablePositiveInt($input['contact_person_id'] ?? null);
        $serviceId = $this->resolveServiceId(
            $sameday,
            $input['service_id'] ?? data_get($connection->settings, 'default_service_id')
        );

        $cashOnDelivery = max(0, (float) ($input['cod_amount'] ?? 0));
        $insuredValue = max(0, (float) ($input['insured_value'] ?? 0));
        $serviceTaxIds = $this->normalizeServiceTaxIds($input['service_tax_ids'] ?? []);
        $deliveryInterval = $this->resolveDeliveryIntervalServiceType($input['delivery_interval_id'] ?? null);
        $thirdPartyPickup = $this->resolveThirdPartyPickup($input);
        $parcelPayload = $this->resolveParcels($input);
        $parcels = $parcelPayload['objects'];
        $normalizedParcels = $parcelPayload['normalized'];
        $packageCount = (int) $parcelPayload['package_count'];
        $packageWeightKg = (float) $parcelPayload['package_weight_kg'];

        $packageType = $this->resolvePackageType($input['package_type'] ?? null);
        $awbPaymentType = $this->resolveAwbPaymentType($input['awb_payment_type'] ?? null);
        $codCollectorType = $this->resolveCodCollectorType($input['cod_collector_type'] ?? null, $cashOnDelivery > 0);
        $recipientCompany = $this->resolveRecipientCompany($input);

        $recipientClass = '\\Sameday\\Objects\\PostAwb\\Request\\AwbRecipientEntityObject';
        $postAwbRequestClass = '\\Sameday\\Requests\\SamedayPostAwbRequest';

        $recipient = new $recipientClass(
            trim((string) ($input['recipient_city'] ?? '')),
            trim((string) ($input['recipient_county'] ?? '')),
            trim((string) ($input['recipient_address'] ?? '')),
            trim((string) ($input['recipient_name'] ?? '')),
            trim((string) ($input['recipient_phone'] ?? '')),
            trim((string) ($input['recipient_email'] ?? '')),
            $recipientCompany,
            filled($input['recipient_postal_code'] ?? null) ? trim((string) $input['recipient_postal_code']) : null
        );

        $request = new $postAwbRequestClass(
            $pickupPointId,
            $contactPersonId,
            $packageType,
            $parcels,
            $serviceId,
            $awbPaymentType,
            $recipient,
            $insuredValue,
            $cashOnDelivery,
            $codCollectorType,
            $thirdPartyPickup,
            $serviceTaxIds,
            $deliveryInterval,
            filled($input['reference'] ?? null) ? trim((string) $input['reference']) : null,
            filled($input['observation'] ?? null) ? trim((string) $input['observation']) : null,
            filled($input['price_observation'] ?? null) ? trim((string) $input['price_observation']) : null,
            filled($input['client_observation'] ?? null) ? trim((string) $input['client_observation']) : null,
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
                'contact_person_id' => $contactPersonId,
                'service_id' => $serviceId,
                'service_tax_ids' => $serviceTaxIds,
                'delivery_interval_id' => $deliveryInterval?->getType(),
                'recipient_name' => trim((string) ($input['recipient_name'] ?? '')),
                'recipient_phone' => trim((string) ($input['recipient_phone'] ?? '')),
                'recipient_email' => filled($input['recipient_email'] ?? null) ? trim((string) $input['recipient_email']) : null,
                'recipient_type' => $this->normalizeRecipientType($input['recipient_type'] ?? null),
                'recipient_company_name' => filled($input['recipient_company_name'] ?? null) ? trim((string) $input['recipient_company_name']) : null,
                'recipient_company_cui' => filled($input['recipient_company_cui'] ?? null) ? trim((string) $input['recipient_company_cui']) : null,
                'recipient_company_onrc' => filled($input['recipient_company_onrc'] ?? null) ? trim((string) $input['recipient_company_onrc']) : null,
                'recipient_company_iban' => filled($input['recipient_company_iban'] ?? null) ? trim((string) $input['recipient_company_iban']) : null,
                'recipient_company_bank' => filled($input['recipient_company_bank'] ?? null) ? trim((string) $input['recipient_company_bank']) : null,
                'recipient_county' => trim((string) ($input['recipient_county'] ?? '')),
                'recipient_city' => trim((string) ($input['recipient_city'] ?? '')),
                'recipient_address' => trim((string) ($input['recipient_address'] ?? '')),
                'recipient_postal_code' => filled($input['recipient_postal_code'] ?? null) ? trim((string) $input['recipient_postal_code']) : null,
                'package_count' => $packageCount,
                'package_weight_kg' => $packageWeightKg,
                'package_type' => method_exists($packageType, 'getType') ? $packageType->getType() : null,
                'awb_payment_type' => method_exists($awbPaymentType, 'getType') ? $awbPaymentType->getType() : null,
                'cod_collector_type' => $codCollectorType?->getType(),
                'parcels' => $normalizedParcels,
                'cod_amount' => $cashOnDelivery,
                'insured_value' => $insuredValue,
                'reference' => filled($input['reference'] ?? null) ? trim((string) $input['reference']) : null,
                'observation' => filled($input['observation'] ?? null) ? trim((string) $input['observation']) : null,
                'price_observation' => filled($input['price_observation'] ?? null) ? trim((string) $input['price_observation']) : null,
                'client_observation' => filled($input['client_observation'] ?? null) ? trim((string) $input['client_observation']) : null,
                'third_party_pickup' => $thirdPartyPickup !== null,
                'third_party' => $thirdPartyPickup?->getFields(),
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
        $contactPersonId = $this->nullablePositiveInt($input['contact_person_id'] ?? null);
        $serviceId = $this->resolveServiceId(
            $sameday,
            $input['service_id'] ?? data_get($connection->settings, 'default_service_id')
        );

        $cashOnDelivery = max(0, (float) ($input['cod_amount'] ?? 0));
        $insuredValue = max(0, (float) ($input['insured_value'] ?? 0));
        $serviceTaxIds = $this->normalizeServiceTaxIds($input['service_tax_ids'] ?? []);
        $thirdPartyPickup = $this->resolveThirdPartyPickup($input);
        $parcelPayload = $this->resolveParcels($input);
        $parcels = $parcelPayload['objects'];
        $packageCount = (int) $parcelPayload['package_count'];
        $packageWeightKg = (float) $parcelPayload['package_weight_kg'];

        $recipientName = $this->requireFilledString($input, 'recipient_name', 'Nume destinatar');
        $recipientPhone = $this->requireFilledString($input, 'recipient_phone', 'Telefon destinatar');
        $recipientCounty = $this->requireFilledString($input, 'recipient_county', 'Județ');
        $recipientCity = $this->requireFilledString($input, 'recipient_city', 'Oraș');
        $recipientAddress = $this->requireFilledString($input, 'recipient_address', 'Adresă');
        $recipientEmail = filled($input['recipient_email'] ?? null) ? trim((string) $input['recipient_email']) : '';
        $recipientPostalCode = filled($input['recipient_postal_code'] ?? null) ? trim((string) $input['recipient_postal_code']) : null;
        $recipientCompany = $this->resolveRecipientCompany($input);

        $recipientClass = '\\Sameday\\Objects\\PostAwb\\Request\\AwbRecipientEntityObject';
        $estimateRequestClass = '\\Sameday\\Requests\\SamedayPostAwbEstimationRequest';

        $packageType = $this->resolvePackageType($input['package_type'] ?? null);
        $awbPaymentType = $this->resolveAwbPaymentType($input['awb_payment_type'] ?? null);

        $recipient = new $recipientClass(
            $recipientCity,
            $recipientCounty,
            $recipientAddress,
            $recipientName,
            $recipientPhone,
            $recipientEmail,
            $recipientCompany,
            $recipientPostalCode
        );

        $request = new $estimateRequestClass(
            $pickupPointId,
            $contactPersonId,
            $packageType,
            $parcels,
            $serviceId,
            $awbPaymentType,
            $recipient,
            $insuredValue,
            $cashOnDelivery,
            $thirdPartyPickup,
            $serviceTaxIds,
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
            'package_count' => $packageCount,
            'package_weight_kg' => $packageWeightKg,
            'service_tax_ids' => $serviceTaxIds,
        ];
    }

    /**
     * Cancels an existing AWB in Sameday.
     *
     * @return array<string, mixed>
     */
    public function cancelAwb(IntegrationConnection $connection, string $awbNumber): array
    {
        if (! $connection->isSameday() || ! $connection->is_active) {
            throw new RuntimeException('Conexiunea selectată nu este Sameday activă.');
        }

        $awbNumber = trim($awbNumber);
        if ($awbNumber === '') {
            throw new RuntimeException('Numărul AWB este obligatoriu pentru anulare.');
        }

        $deleteAwbRequestClass = '\\Sameday\\Requests\\SamedayDeleteAwbRequest';
        if (! class_exists($deleteAwbRequestClass)) {
            throw new RuntimeException('Sameday SDK lipsește. Rulează: composer require sameday-courier/php-sdk');
        }

        $sameday = $this->newSamedayInstance($connection);
        $request = new $deleteAwbRequestClass($awbNumber);
        $response = $sameday->deleteAwb($request);

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
            'awb_number' => $awbNumber,
            'response_payload' => $decodedResponse,
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

    /**
     * @return array<int, string>
     */
    public function getContactPersonOptions(IntegrationConnection $connection, ?int $pickupPointId = null): array
    {
        $sameday = $this->newSamedayInstance($connection);
        $requestClass = '\\Sameday\\Requests\\SamedayGetPickupPointsRequest';

        $request = new $requestClass();
        if (method_exists($request, 'setCountPerPage')) {
            $request->setCountPerPage(100);
        }

        $response = $sameday->getPickupPoints($request);
        $pickupPoints = $response->getPickupPoints();

        if (! is_array($pickupPoints) || $pickupPoints === []) {
            return [];
        }

        $targetPickupPoint = null;
        $pickupPointId = max(0, (int) $pickupPointId);
        if ($pickupPointId > 0) {
            foreach ($pickupPoints as $pickupPoint) {
                if ((int) $pickupPoint->getId() === $pickupPointId) {
                    $targetPickupPoint = $pickupPoint;
                    break;
                }
            }
        }

        if (! $targetPickupPoint) {
            foreach ($pickupPoints as $pickupPoint) {
                if (method_exists($pickupPoint, 'isDefault') && $pickupPoint->isDefault()) {
                    $targetPickupPoint = $pickupPoint;
                    break;
                }
            }
        }

        $targetPickupPoint ??= $pickupPoints[0];

        if (! method_exists($targetPickupPoint, 'getContactPersons')) {
            return [];
        }

        $options = [];
        foreach ($targetPickupPoint->getContactPersons() as $contactPerson) {
            $contactPersonId = (int) $contactPerson->getId();
            if ($contactPersonId <= 0) {
                continue;
            }

            $label = (string) $contactPerson->getName();
            if (method_exists($contactPerson, 'getPhone')) {
                $phone = trim((string) $contactPerson->getPhone());
                if ($phone !== '') {
                    $label .= ' ('.$phone.')';
                }
            }
            if (method_exists($contactPerson, 'isDefault') && $contactPerson->isDefault()) {
                $label .= ' (default)';
            }

            $options[$contactPersonId] = $label;
        }

        return $options;
    }

    /**
     * @return array<int, string>
     */
    public function getServiceTaxOptions(IntegrationConnection $connection, ?int $serviceId = null): array
    {
        $sameday = $this->newSamedayInstance($connection);
        $requestClass = '\\Sameday\\Requests\\SamedayGetServicesRequest';

        $request = new $requestClass();
        if (method_exists($request, 'setCountPerPage')) {
            $request->setCountPerPage(100);
        }

        $response = $sameday->getServices($request);
        $options = [];

        $serviceId = max(0, (int) $serviceId);
        foreach ($response->getServices() as $service) {
            $currentServiceId = (int) $service->getId();
            if ($serviceId > 0 && $currentServiceId !== $serviceId) {
                continue;
            }

            if (! method_exists($service, 'getOptionalTaxes')) {
                if ($serviceId > 0) {
                    break;
                }

                continue;
            }

            foreach ($service->getOptionalTaxes() as $optionalTax) {
                $optionalTaxId = (int) $optionalTax->getId();
                if ($optionalTaxId <= 0) {
                    continue;
                }

                $label = (string) $optionalTax->getName();
                if (method_exists($optionalTax, 'getCode') && $optionalTax->getCode() !== '') {
                    $label .= ' ['.$optionalTax->getCode().']';
                }
                if (method_exists($optionalTax, 'getTax')) {
                    $label .= ' - '.number_format((float) $optionalTax->getTax(), 2).' RON';
                }

                $options[$optionalTaxId] = $label;
            }

            if ($serviceId > 0) {
                break;
            }
        }

        return $options;
    }

    /**
     * @return array<int, string>
     */
    public function getDeliveryIntervalOptions(IntegrationConnection $connection, ?int $serviceId = null): array
    {
        $serviceId = max(0, (int) $serviceId);
        if ($serviceId <= 0) {
            return [];
        }

        $sameday = $this->newSamedayInstance($connection);
        $requestClass = '\\Sameday\\Requests\\SamedayGetServicesRequest';
        $deliveryIntervalClass = '\\Sameday\\Objects\\Types\\DeliveryIntervalServiceType';

        if (! class_exists($deliveryIntervalClass) || ! method_exists($deliveryIntervalClass, 'getDeliveryIntervals')) {
            return [];
        }

        $request = new $requestClass();
        if (method_exists($request, 'setCountPerPage')) {
            $request->setCountPerPage(100);
        }

        $response = $sameday->getServices($request);
        $serviceCode = null;

        foreach ($response->getServices() as $service) {
            if ((int) $service->getId() !== $serviceId) {
                continue;
            }

            if (method_exists($service, 'getCode')) {
                $code = trim((string) $service->getCode());
                $serviceCode = $code !== '' ? $code : null;
            }

            break;
        }

        if (! $serviceCode) {
            return [];
        }

        $intervals = $deliveryIntervalClass::getDeliveryIntervals($serviceCode);
        if (! is_array($intervals) || $intervals === []) {
            return [];
        }

        $options = [];
        foreach ($intervals as $intervalId => $intervalData) {
            $intervalId = (int) $intervalId;
            if ($intervalId <= 0 || ! is_array($intervalData)) {
                continue;
            }

            $startHour = (int) ($intervalData['startHour'] ?? 0);
            $endHour = (int) ($intervalData['endHour'] ?? 0);
            if ($startHour <= 0 || $endHour <= 0) {
                continue;
            }

            $options[$intervalId] = sprintf('%02d:00 - %02d:00', $startHour, $endHour);
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
            '\\Sameday\\Requests\\SamedayDeleteAwbRequest',
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
     * @return array{
     *     objects: array<int, object>,
     *     normalized: array<int, array<string, float|null>>,
     *     package_count: int,
     *     package_weight_kg: float
     * }
     */
    private function resolveParcels(array $input): array
    {
        $parcelDimensionsClass = '\\Sameday\\Objects\\ParcelDimensionsObject';

        $fallbackCount = max(1, (int) ($input['package_count'] ?? 1));
        $fallbackWeight = max(0.01, (float) ($input['package_weight_kg'] ?? 1));
        $rawParcels = $input['parcels'] ?? [];

        $normalizedParcels = [];
        if (is_array($rawParcels)) {
            foreach ($rawParcels as $parcelRow) {
                if (! is_array($parcelRow)) {
                    continue;
                }

                $weight = (float) ($parcelRow['weight_kg'] ?? 0);
                if ($weight <= 0) {
                    continue;
                }

                $normalizedParcels[] = [
                    'weight_kg' => max(0.01, $weight),
                    'width_cm' => $this->nullablePositiveFloat($parcelRow['width_cm'] ?? null),
                    'length_cm' => $this->nullablePositiveFloat($parcelRow['length_cm'] ?? null),
                    'height_cm' => $this->nullablePositiveFloat($parcelRow['height_cm'] ?? null),
                ];
            }
        }

        if ($normalizedParcels === []) {
            for ($index = 0; $index < $fallbackCount; $index++) {
                $normalizedParcels[] = [
                    'weight_kg' => $fallbackWeight,
                    'width_cm' => null,
                    'length_cm' => null,
                    'height_cm' => null,
                ];
            }
        }

        $parcelObjects = array_map(
            static fn (array $parcel): object => new $parcelDimensionsClass(
                $parcel['weight_kg'],
                $parcel['width_cm'],
                $parcel['length_cm'],
                $parcel['height_cm']
            ),
            $normalizedParcels
        );

        return [
            'objects' => $parcelObjects,
            'normalized' => $normalizedParcels,
            'package_count' => count($normalizedParcels),
            'package_weight_kg' => (float) ($normalizedParcels[0]['weight_kg'] ?? $fallbackWeight),
        ];
    }

    private function resolvePackageType(mixed $value): object
    {
        $packageTypeClass = '\\Sameday\\Objects\\Types\\PackageType';
        $normalizedValue = is_string($value) ? strtolower(trim($value)) : $value;

        $type = match (true) {
            $normalizedValue === 1,
            $normalizedValue === '1',
            $normalizedValue === 'envelope' => constant($packageTypeClass.'::ENVELOPE'),
            $normalizedValue === 2,
            $normalizedValue === '2',
            $normalizedValue === 'large' => constant($packageTypeClass.'::LARGE'),
            default => constant($packageTypeClass.'::PARCEL'),
        };

        return new $packageTypeClass($type);
    }

    private function resolveAwbPaymentType(mixed $value): object
    {
        $awbPaymentTypeClass = '\\Sameday\\Objects\\Types\\AwbPaymentType';
        $normalizedValue = is_string($value) ? strtolower(trim($value)) : $value;

        $type = match (true) {
            is_numeric($normalizedValue) && (int) $normalizedValue > 0 => (int) $normalizedValue,
            default => constant($awbPaymentTypeClass.'::CLIENT'),
        };

        return new $awbPaymentTypeClass($type);
    }

    private function resolveCodCollectorType(mixed $value, bool $codEnabled): ?object
    {
        if (! $codEnabled) {
            return null;
        }

        $codCollectorTypeClass = '\\Sameday\\Objects\\Types\\CodCollectorType';
        if (! class_exists($codCollectorTypeClass)) {
            throw new RuntimeException('Sameday SDK nu suportă tipul de colector ramburs.');
        }

        $normalizedValue = is_string($value) ? strtolower(trim($value)) : $value;

        $type = match (true) {
            is_numeric($normalizedValue) && (int) $normalizedValue > 0 => (int) $normalizedValue,
            default => constant($codCollectorTypeClass.'::CLIENT'),
        };

        return new $codCollectorTypeClass($type);
    }

    private function resolveDeliveryIntervalServiceType(mixed $value): ?object
    {
        $deliveryIntervalId = $this->nullablePositiveInt($value);
        if (! $deliveryIntervalId) {
            return null;
        }

        $deliveryIntervalTypeClass = '\\Sameday\\Objects\\Types\\DeliveryIntervalServiceType';
        if (! class_exists($deliveryIntervalTypeClass)) {
            throw new RuntimeException('Sameday SDK nu suportă intervalele de livrare.');
        }

        return new $deliveryIntervalTypeClass($deliveryIntervalId);
    }

    private function normalizeRecipientType(mixed $value): string
    {
        return strtolower(trim((string) $value)) === 'company' ? 'company' : 'individual';
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function resolveRecipientCompany(array $input): ?object
    {
        $recipientType = $this->normalizeRecipientType($input['recipient_type'] ?? null);
        $hasCompanyName = filled($input['recipient_company_name'] ?? null);
        if ($recipientType !== 'company' && ! $hasCompanyName) {
            return null;
        }

        $companyClass = '\\Sameday\\Objects\\PostAwb\\Request\\CompanyEntityObject';
        if (! class_exists($companyClass)) {
            throw new RuntimeException('Sameday SDK nu suportă datele companiei destinatarului.');
        }

        $name = $this->requireFilledString($input, 'recipient_company_name', 'Companie destinatar');
        $cui = filled($input['recipient_company_cui'] ?? null) ? trim((string) $input['recipient_company_cui']) : '';
        $onrcNumber = filled($input['recipient_company_onrc'] ?? null) ? trim((string) $input['recipient_company_onrc']) : '';
        $iban = filled($input['recipient_company_iban'] ?? null) ? trim((string) $input['recipient_company_iban']) : '';
        $bank = filled($input['recipient_company_bank'] ?? null) ? trim((string) $input['recipient_company_bank']) : '';

        return new $companyClass($name, $cui, $onrcNumber, $iban, $bank);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function resolveThirdPartyPickup(array $input): ?object
    {
        $enabled = filter_var($input['third_party_pickup'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (! $enabled) {
            return null;
        }

        $thirdPartyClass = '\\Sameday\\Objects\\PostAwb\\Request\\ThirdPartyPickupEntityObject';
        if (! class_exists($thirdPartyClass)) {
            throw new RuntimeException('Sameday SDK nu suportă ThirdPartyPickupEntityObject.');
        }

        $city = $this->requireFilledString($input, 'third_party_city', 'Oraș terț ridicare');
        $county = $this->requireFilledString($input, 'third_party_county', 'Județ terț ridicare');
        $address = $this->requireFilledString($input, 'third_party_address', 'Adresă terț ridicare');
        $name = $this->requireFilledString($input, 'third_party_name', 'Nume terț ridicare');
        $phone = $this->requireFilledString($input, 'third_party_phone', 'Telefon terț ridicare');
        $postalCode = filled($input['third_party_postal_code'] ?? null) ? trim((string) $input['third_party_postal_code']) : null;

        return new $thirdPartyClass(
            $city,
            $county,
            $address,
            $name,
            $phone,
            null,
            $postalCode
        );
    }

    /**
     * @return array<int, int>
     */
    private function normalizeServiceTaxIds(mixed $value): array
    {
        $values = [];

        if (is_array($value)) {
            $values = $value;
        } elseif (is_string($value)) {
            $values = array_map('trim', explode(',', $value));
        } elseif ($value !== null) {
            $values = [$value];
        }

        $normalized = [];
        foreach ($values as $entry) {
            $entry = (int) $entry;
            if ($entry > 0) {
                $normalized[] = $entry;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function nullablePositiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $intValue = (int) $value;

        return $intValue > 0 ? $intValue : null;
    }

    private function nullablePositiveFloat(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $floatValue = (float) $value;

        return $floatValue > 0 ? $floatValue : null;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function requireFilledString(array $input, string $key, string $label): string
    {
        $value = trim((string) ($input[$key] ?? ''));

        if ($value === '') {
            throw new RuntimeException("Completează câmpul „{$label}”.");
        }

        return $value;
    }
}
