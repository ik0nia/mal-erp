<?php

namespace App\Services\Courier;

use App\Models\IntegrationConnection;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class SamedayAwbService
{
    private const OPTIONS_CACHE_TTL_MINUTES = 15;

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
            $input['pickup_point_id'] ?? data_get($connection->settings, 'pickup_point_id'),
            $connection
        );
        $contactPersonId = $this->nullablePositiveInt($input['contact_person_id'] ?? null);
        $serviceId = $this->resolveServiceId(
            $sameday,
            $input['service_id'] ?? data_get($connection->settings, 'default_service_id'),
            $connection
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
        $resolvedCounty = $this->resolveRecipientCounty($input);
        $resolvedCity = $this->resolveRecipientCity($input);
        $resolvedAddress = $this->buildRecipientAddress($input);

        $recipientClass = '\\Sameday\\Objects\\PostAwb\\Request\\AwbRecipientEntityObject';
        $postAwbRequestClass = '\\Sameday\\Requests\\SamedayPostAwbRequest';

        $recipient = new $recipientClass(
            $resolvedCity['value'],
            $resolvedCounty['value'],
            $resolvedAddress,
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
                'recipient_county_id' => $resolvedCounty['id'],
                'recipient_city_id' => $resolvedCity['id'],
                'recipient_county' => $resolvedCounty['name'],
                'recipient_city' => $resolvedCity['name'],
                'recipient_address' => $resolvedAddress,
                'recipient_street' => filled($input['recipient_street'] ?? null) ? trim((string) $input['recipient_street']) : null,
                'recipient_street_no' => filled($input['recipient_street_no'] ?? null) ? trim((string) $input['recipient_street_no']) : null,
                'recipient_block' => filled($input['recipient_block'] ?? null) ? trim((string) $input['recipient_block']) : null,
                'recipient_staircase' => filled($input['recipient_staircase'] ?? null) ? trim((string) $input['recipient_staircase']) : null,
                'recipient_floor' => filled($input['recipient_floor'] ?? null) ? trim((string) $input['recipient_floor']) : null,
                'recipient_apartment' => filled($input['recipient_apartment'] ?? null) ? trim((string) $input['recipient_apartment']) : null,
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
            $input['pickup_point_id'] ?? data_get($connection->settings, 'pickup_point_id'),
            $connection
        );
        $contactPersonId = $this->nullablePositiveInt($input['contact_person_id'] ?? null);
        $serviceId = $this->resolveServiceId(
            $sameday,
            $input['service_id'] ?? data_get($connection->settings, 'default_service_id'),
            $connection
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
        $resolvedCounty = $this->resolveRecipientCounty($input);
        $resolvedCity = $this->resolveRecipientCity($input);
        $recipientAddress = $this->buildRecipientAddress($input);
        $recipientEmail = filled($input['recipient_email'] ?? null) ? trim((string) $input['recipient_email']) : '';
        $recipientPostalCode = filled($input['recipient_postal_code'] ?? null) ? trim((string) $input['recipient_postal_code']) : null;
        $recipientCompany = $this->resolveRecipientCompany($input);

        $recipientClass = '\\Sameday\\Objects\\PostAwb\\Request\\AwbRecipientEntityObject';
        $estimateRequestClass = '\\Sameday\\Requests\\SamedayPostAwbEstimationRequest';

        $packageType = $this->resolvePackageType($input['package_type'] ?? null);
        $awbPaymentType = $this->resolveAwbPaymentType($input['awb_payment_type'] ?? null);

        $recipient = new $recipientClass(
            $resolvedCity['value'],
            $resolvedCounty['value'],
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
        $options = [];

        foreach ($this->getPickupPointDataset($connection) as $pickupPointData) {
            $pickupPointId = (int) ($pickupPointData['id'] ?? 0);
            if ($pickupPointId <= 0) {
                continue;
            }

            $options[$pickupPointId] = (string) ($pickupPointData['label'] ?? "Pickup #{$pickupPointId}");
        }

        return $options;
    }

    /**
     * @return array<int, string>
     */
    public function getServiceOptions(IntegrationConnection $connection): array
    {
        $options = [];

        foreach ($this->getServiceDataset($connection) as $serviceData) {
            $serviceId = (int) ($serviceData['id'] ?? 0);
            if ($serviceId <= 0) {
                continue;
            }

            $options[$serviceId] = (string) ($serviceData['label'] ?? "Service #{$serviceId}");
        }

        return $options;
    }

    /**
     * @return array<int, string>
     */
    public function getCountyOptions(IntegrationConnection $connection): array
    {
        return $this->rememberConnectionCache($connection, 'counties-options-v1', function () use ($connection): array {
            $sameday = $this->newSamedayInstance($connection);
            $requestClass = '\\Sameday\\Requests\\SamedayGetCountiesRequest';

            $options = [];
            $page = 1;
            $pages = 1;

            do {
                $request = new $requestClass(null);
                if (method_exists($request, 'setCountPerPage')) {
                    $request->setCountPerPage(200);
                }
                if (method_exists($request, 'setPage')) {
                    $request->setPage($page);
                }

                $response = $sameday->getCounties($request);

                foreach ($response->getCounties() as $county) {
                    $countyId = (int) $county->getId();
                    if ($countyId <= 0) {
                        continue;
                    }

                    $options[$countyId] = trim((string) $county->getName());
                }

                $pages = method_exists($response, 'getPages') ? max(1, (int) $response->getPages()) : 1;
                $page++;
            } while ($page <= $pages);

            asort($options, SORT_NATURAL | SORT_FLAG_CASE);

            return $options;
        });
    }

    /**
     * @return array<int, string>
     */
    public function getCityOptions(IntegrationConnection $connection, ?int $countyId = null): array
    {
        $countyId = max(0, (int) $countyId);
        if ($countyId <= 0) {
            return [];
        }

        return $this->rememberConnectionCache($connection, "cities-options-county-{$countyId}-v1", function () use ($connection, $countyId): array {
            $sameday = $this->newSamedayInstance($connection);
            $requestClass = '\\Sameday\\Requests\\SamedayGetCitiesRequest';

            $options = [];
            $page = 1;
            $pages = 1;

            do {
                $request = new $requestClass($countyId);
                if (method_exists($request, 'setCountPerPage')) {
                    $request->setCountPerPage(200);
                }
                if (method_exists($request, 'setPage')) {
                    $request->setPage($page);
                }

                $response = $sameday->getCities($request);

                foreach ($response->getCities() as $city) {
                    $cityId = (int) $city->getId();
                    if ($cityId <= 0) {
                        continue;
                    }

                    $label = trim((string) $city->getName());
                    if (method_exists($city, 'getVillage')) {
                        $village = trim((string) $city->getVillage());
                        if ($village !== '') {
                            $label .= ' ('.$village.')';
                        }
                    }

                    $options[$cityId] = $label;
                }

                $pages = method_exists($response, 'getPages') ? max(1, (int) $response->getPages()) : 1;
                $page++;
            } while ($page <= $pages);

            asort($options, SORT_NATURAL | SORT_FLAG_CASE);

            return $options;
        });
    }

    /**
     * @return array<int, string>
     */
    public function getContactPersonOptions(IntegrationConnection $connection, ?int $pickupPointId = null): array
    {
        $pickupPoints = $this->getPickupPointDataset($connection);
        if ($pickupPoints === []) {
            return [];
        }

        $targetPickupPoint = null;
        $pickupPointId = max(0, (int) $pickupPointId);
        if ($pickupPointId > 0) {
            foreach ($pickupPoints as $pickupPoint) {
                if ((int) ($pickupPoint['id'] ?? 0) === $pickupPointId) {
                    $targetPickupPoint = $pickupPoint;
                    break;
                }
            }
        }

        if (! $targetPickupPoint) {
            foreach ($pickupPoints as $pickupPoint) {
                if ((bool) ($pickupPoint['is_default'] ?? false)) {
                    $targetPickupPoint = $pickupPoint;
                    break;
                }
            }
        }

        $targetPickupPoint ??= $pickupPoints[0];
        $contactPersons = $targetPickupPoint['contact_persons'] ?? [];

        $options = [];
        foreach ($contactPersons as $contactPerson) {
            $contactPersonId = (int) ($contactPerson['id'] ?? 0);
            if ($contactPersonId <= 0) {
                continue;
            }

            $options[$contactPersonId] = (string) ($contactPerson['label'] ?? "Contact #{$contactPersonId}");
        }

        return $options;
    }

    /**
     * @return array<int, string>
     */
    public function getServiceTaxOptions(IntegrationConnection $connection, ?int $serviceId = null): array
    {
        $services = $this->getServiceDataset($connection);
        $options = [];

        $serviceId = max(0, (int) $serviceId);
        foreach ($services as $service) {
            $currentServiceId = (int) ($service['id'] ?? 0);
            if ($serviceId > 0 && $currentServiceId !== $serviceId) {
                continue;
            }

            $optionalTaxes = $service['optional_taxes'] ?? [];
            foreach ($optionalTaxes as $optionalTax) {
                $optionalTaxId = (int) ($optionalTax['id'] ?? 0);
                if ($optionalTaxId <= 0) {
                    continue;
                }

                $options[$optionalTaxId] = (string) ($optionalTax['label'] ?? "Tax #{$optionalTaxId}");
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

        $services = $this->getServiceDataset($connection);
        foreach ($services as $service) {
            if ((int) ($service['id'] ?? 0) !== $serviceId) {
                continue;
            }

            return is_array($service['delivery_intervals'] ?? null)
                ? $service['delivery_intervals']
                : [];
        }

        return [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getPickupPointDataset(IntegrationConnection $connection): array
    {
        return $this->rememberConnectionCache($connection, 'pickup-points-dataset-v1', function () use ($connection): array {
            $sameday = $this->newSamedayInstance($connection);
            $requestClass = '\\Sameday\\Requests\\SamedayGetPickupPointsRequest';

            $dataset = [];
            $page = 1;
            $pages = 1;

            do {
                $request = new $requestClass();
                if (method_exists($request, 'setCountPerPage')) {
                    $request->setCountPerPage(200);
                }
                if (method_exists($request, 'setPage')) {
                    $request->setPage($page);
                }

                $response = $sameday->getPickupPoints($request);

                foreach ($response->getPickupPoints() as $pickupPoint) {
                    $pickupPointId = (int) $pickupPoint->getId();
                    if ($pickupPointId <= 0) {
                        continue;
                    }

                    $city = '';
                    if (method_exists($pickupPoint, 'getCity')) {
                        $cityObject = $pickupPoint->getCity();
                        if (is_object($cityObject) && method_exists($cityObject, 'getName')) {
                            $city = trim((string) $cityObject->getName());
                        }
                    }

                    $alias = method_exists($pickupPoint, 'getAlias') ? trim((string) $pickupPoint->getAlias()) : '';
                    $address = method_exists($pickupPoint, 'getAddress') ? trim((string) $pickupPoint->getAddress()) : '';
                    $isDefault = method_exists($pickupPoint, 'isDefault') && $pickupPoint->isDefault();

                    $parts = array_filter([$alias, $city, $address]);
                    $label = implode(' - ', $parts);
                    if ($isDefault) {
                        $label .= ' (default)';
                    }
                    $label = $label !== '' ? $label : "Pickup #{$pickupPointId}";

                    $contactPersons = [];
                    if (method_exists($pickupPoint, 'getContactPersons')) {
                        foreach ($pickupPoint->getContactPersons() as $contactPerson) {
                            $contactPersonId = (int) $contactPerson->getId();
                            if ($contactPersonId <= 0) {
                                continue;
                            }

                            $contactLabel = trim((string) $contactPerson->getName());
                            if (method_exists($contactPerson, 'getPhone')) {
                                $phone = trim((string) $contactPerson->getPhone());
                                if ($phone !== '') {
                                    $contactLabel .= ' ('.$phone.')';
                                }
                            }

                            $contactIsDefault = method_exists($contactPerson, 'isDefault') && $contactPerson->isDefault();
                            if ($contactIsDefault) {
                                $contactLabel .= ' (default)';
                            }

                            $contactPersons[] = [
                                'id' => $contactPersonId,
                                'label' => $contactLabel !== '' ? $contactLabel : "Contact #{$contactPersonId}",
                                'is_default' => $contactIsDefault,
                            ];
                        }
                    }

                    $dataset[] = [
                        'id' => $pickupPointId,
                        'label' => $label,
                        'is_default' => $isDefault,
                        'contact_persons' => $contactPersons,
                    ];
                }

                $pages = method_exists($response, 'getPages') ? max(1, (int) $response->getPages()) : 1;
                $page++;
            } while ($page <= $pages);

            return $dataset;
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getServiceDataset(IntegrationConnection $connection): array
    {
        return $this->rememberConnectionCache($connection, 'services-dataset-v1', function () use ($connection): array {
            $sameday = $this->newSamedayInstance($connection);
            $requestClass = '\\Sameday\\Requests\\SamedayGetServicesRequest';
            $deliveryIntervalClass = '\\Sameday\\Objects\\Types\\DeliveryIntervalServiceType';
            $supportsDeliveryIntervals = class_exists($deliveryIntervalClass) && method_exists($deliveryIntervalClass, 'getDeliveryIntervals');

            $dataset = [];
            $page = 1;
            $pages = 1;

            do {
                $request = new $requestClass();
                if (method_exists($request, 'setCountPerPage')) {
                    $request->setCountPerPage(200);
                }
                if (method_exists($request, 'setPage')) {
                    $request->setPage($page);
                }

                $response = $sameday->getServices($request);

                foreach ($response->getServices() as $service) {
                    $serviceId = (int) $service->getId();
                    if ($serviceId <= 0) {
                        continue;
                    }

                    $name = trim((string) $service->getName());
                    $code = method_exists($service, 'getCode') ? trim((string) $service->getCode()) : '';
                    $isDefault = method_exists($service, 'isDefault') && $service->isDefault();

                    $label = $name;
                    if ($code !== '') {
                        $label .= ' ['.$code.']';
                    }
                    if ($isDefault) {
                        $label .= ' (default)';
                    }
                    $label = $label !== '' ? $label : "Service #{$serviceId}";

                    $optionalTaxes = [];
                    if (method_exists($service, 'getOptionalTaxes')) {
                        foreach ($service->getOptionalTaxes() as $optionalTax) {
                            $optionalTaxId = (int) $optionalTax->getId();
                            if ($optionalTaxId <= 0) {
                                continue;
                            }

                            $optionalTaxLabel = trim((string) $optionalTax->getName());
                            if (method_exists($optionalTax, 'getCode') && $optionalTax->getCode() !== '') {
                                $optionalTaxLabel .= ' ['.$optionalTax->getCode().']';
                            }
                            if (method_exists($optionalTax, 'getTax')) {
                                $optionalTaxLabel .= ' - '.number_format((float) $optionalTax->getTax(), 2).' RON';
                            }
                            $optionalTaxLabel = $optionalTaxLabel !== '' ? $optionalTaxLabel : "Tax #{$optionalTaxId}";

                            $optionalTaxes[] = [
                                'id' => $optionalTaxId,
                                'label' => $optionalTaxLabel,
                            ];
                        }
                    }

                    $deliveryIntervals = [];
                    if ($supportsDeliveryIntervals && $code !== '') {
                        $rawIntervals = $deliveryIntervalClass::getDeliveryIntervals($code);
                        if (is_array($rawIntervals)) {
                            foreach ($rawIntervals as $intervalId => $intervalData) {
                                $intervalId = (int) $intervalId;
                                if ($intervalId <= 0 || ! is_array($intervalData)) {
                                    continue;
                                }

                                $startHour = (int) ($intervalData['startHour'] ?? 0);
                                $endHour = (int) ($intervalData['endHour'] ?? 0);
                                if ($startHour <= 0 || $endHour <= 0) {
                                    continue;
                                }

                                $deliveryIntervals[$intervalId] = sprintf('%02d:00 - %02d:00', $startHour, $endHour);
                            }
                        }
                    }

                    $dataset[] = [
                        'id' => $serviceId,
                        'label' => $label,
                        'is_default' => $isDefault,
                        'optional_taxes' => $optionalTaxes,
                        'delivery_intervals' => $deliveryIntervals,
                    ];
                }

                $pages = method_exists($response, 'getPages') ? max(1, (int) $response->getPages()) : 1;
                $page++;
            } while ($page <= $pages);

            return $dataset;
        });
    }

    /**
     * @template T
     *
     * @param  callable():T  $resolver
     * @return T
     */
    private function rememberConnectionCache(IntegrationConnection $connection, string $suffix, callable $resolver): mixed
    {
        $cacheKey = $this->buildConnectionCacheKey($connection, $suffix);

        return Cache::remember(
            $cacheKey,
            now()->addMinutes(self::OPTIONS_CACHE_TTL_MINUTES),
            $resolver
        );
    }

    private function buildConnectionCacheKey(IntegrationConnection $connection, string $suffix): string
    {
        $updatedAtTimestamp = $connection->updated_at?->getTimestamp() ?? 0;

        return implode(':', [
            'sameday',
            'connection',
            (string) $connection->id,
            (string) $updatedAtTimestamp,
            $suffix,
        ]);
    }

    private function resolvePickupPointId(object $sameday, mixed $explicitPickupPointId, IntegrationConnection $connection): int
    {
        $explicitPickupPointId = (int) $explicitPickupPointId;
        if ($explicitPickupPointId > 0) {
            return $explicitPickupPointId;
        }

        $pickupPoints = $this->getPickupPointDataset($connection);
        if ($pickupPoints !== []) {
            foreach ($pickupPoints as $pickupPoint) {
                $pickupPointId = (int) ($pickupPoint['id'] ?? 0);
                if ($pickupPointId > 0 && (bool) ($pickupPoint['is_default'] ?? false)) {
                    return $pickupPointId;
                }
            }

            $firstPickupPointId = (int) ($pickupPoints[0]['id'] ?? 0);
            if ($firstPickupPointId > 0) {
                return $firstPickupPointId;
            }
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

    private function resolveServiceId(object $sameday, mixed $explicitServiceId, IntegrationConnection $connection): int
    {
        $explicitServiceId = (int) $explicitServiceId;
        if ($explicitServiceId > 0) {
            return $explicitServiceId;
        }

        $services = $this->getServiceDataset($connection);
        if ($services !== []) {
            foreach ($services as $service) {
                $serviceId = (int) ($service['id'] ?? 0);
                if ($serviceId > 0 && (bool) ($service['is_default'] ?? false)) {
                    return $serviceId;
                }
            }

            $firstServiceId = (int) ($services[0]['id'] ?? 0);
            if ($firstServiceId > 0) {
                return $firstServiceId;
            }
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
            '\\Sameday\\Requests\\SamedayGetCountiesRequest',
            '\\Sameday\\Requests\\SamedayGetCitiesRequest',
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

    /**
     * @param  array<string, mixed>  $input
     * @return array{id: int|null, value: int|string, name: string}
     */
    private function resolveRecipientCounty(array $input): array
    {
        $countyId = $this->nullablePositiveInt($input['recipient_county_id'] ?? null);
        $countyName = trim((string) ($input['recipient_county'] ?? ''));

        if ($countyId !== null) {
            if ($countyName === '') {
                $countyName = (string) $countyId;
            }

            return [
                'id' => $countyId,
                'value' => $countyId,
                'name' => $countyName,
            ];
        }

        $countyName = $this->requireFilledString($input, 'recipient_county', 'Județ');

        return [
            'id' => null,
            'value' => $countyName,
            'name' => $countyName,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{id: int|null, value: int|string, name: string}
     */
    private function resolveRecipientCity(array $input): array
    {
        $cityId = $this->nullablePositiveInt($input['recipient_city_id'] ?? null);
        $cityName = trim((string) ($input['recipient_city'] ?? ''));

        if ($cityId !== null) {
            if ($cityName === '') {
                $cityName = (string) $cityId;
            }

            return [
                'id' => $cityId,
                'value' => $cityId,
                'name' => $cityName,
            ];
        }

        $cityName = $this->requireFilledString($input, 'recipient_city', 'Oraș');

        return [
            'id' => null,
            'value' => $cityName,
            'name' => $cityName,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function buildRecipientAddress(array $input): string
    {
        $explicitAddress = trim((string) ($input['recipient_address'] ?? ''));
        if ($explicitAddress !== '') {
            return $explicitAddress;
        }

        $street = $this->requireFilledString($input, 'recipient_street', 'Stradă');

        $parts = ["Str. {$street}"];

        $streetNo = trim((string) ($input['recipient_street_no'] ?? ''));
        if ($streetNo !== '') {
            $parts[] = "Nr. {$streetNo}";
        }

        $block = trim((string) ($input['recipient_block'] ?? ''));
        if ($block !== '') {
            $parts[] = "Bl. {$block}";
        }

        $staircase = trim((string) ($input['recipient_staircase'] ?? ''));
        if ($staircase !== '') {
            $parts[] = "Sc. {$staircase}";
        }

        $floor = trim((string) ($input['recipient_floor'] ?? ''));
        if ($floor !== '') {
            $parts[] = "Et. {$floor}";
        }

        $apartment = trim((string) ($input['recipient_apartment'] ?? ''));
        if ($apartment !== '') {
            $parts[] = "Ap. {$apartment}";
        }

        return implode(', ', $parts);
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
