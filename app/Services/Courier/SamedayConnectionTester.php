<?php

namespace App\Services\Courier;

use App\Models\IntegrationConnection;
use RuntimeException;
use Throwable;

class SamedayConnectionTester
{
    /**
     * @throws Throwable
     */
    public function testConnection(IntegrationConnection $connection): void
    {
        if (! $connection->isSameday()) {
            throw new RuntimeException('Connection provider is not sameday.');
        }

        $username = $connection->samedayUsername();
        $password = $connection->samedayPassword();
        $apiUrl = $connection->samedayApiUrl();

        if ($username === '' || $password === '') {
            throw new RuntimeException('Sameday username/password are required.');
        }

        $clientClass = '\\Sameday\\SamedayClient';
        $samedayClass = '\\Sameday\\Sameday';
        $pickupRequestClass = '\\Sameday\\Requests\\SamedayGetPickupPointsRequest';

        if (! class_exists($clientClass) || ! class_exists($samedayClass) || ! class_exists($pickupRequestClass)) {
            throw new RuntimeException(
                'Sameday SDK is not installed. Run: composer require sameday-courier/php-sdk'
            );
        }

        /** @var object $client */
        $client = new $clientClass($username, $password, $apiUrl);
        /** @var object $sameday */
        $sameday = new $samedayClass($client);
        /** @var object $pickupRequest */
        $pickupRequest = new $pickupRequestClass();

        // getPickupPoints triggers auth and validates credentials/API URL.
        $sameday->getPickupPoints($pickupRequest);
    }
}
