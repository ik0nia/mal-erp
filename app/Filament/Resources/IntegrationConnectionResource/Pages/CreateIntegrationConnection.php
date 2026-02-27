<?php

namespace App\Filament\Resources\IntegrationConnectionResource\Pages;

use App\Filament\Resources\IntegrationConnectionResource;
use App\Models\IntegrationConnection;
use Filament\Resources\Pages\CreateRecord;

class CreateIntegrationConnection extends CreateRecord
{
    protected static string $resource = IntegrationConnectionResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (($data['provider'] ?? '') === IntegrationConnection::PROVIDER_WOOCOMMERCE) {
            $data['webhook_secret'] = IntegrationConnection::generateWebhookSecret();
        }

        return $data;
    }
}
