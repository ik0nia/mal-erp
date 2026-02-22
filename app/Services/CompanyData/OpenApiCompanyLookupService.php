<?php

namespace App\Services\CompanyData;

use App\Models\CompanyApiSetting;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenApiCompanyLookupService
{
    /**
     * @return array<string, mixed>
     */
    public function lookupByCui(string $cui, ?CompanyApiSetting $setting = null): array
    {
        $normalizedCui = self::normalizeCui($cui);

        if ($normalizedCui === '') {
            throw new RuntimeException('CUI invalid. Introdu un CUI valid.');
        }

        $activeSetting = $this->resolveSetting($setting);

        if (! filled($activeSetting->api_key)) {
            throw new RuntimeException('API key lipsește în Setări > API date firmă.');
        }

        $response = Http::acceptJson()
            ->timeout($activeSetting->resolveTimeoutSeconds())
            ->withOptions([
                'verify' => $activeSetting->verify_ssl,
            ])
            ->withHeaders([
                'x-api-key' => (string) $activeSetting->api_key,
            ])
            ->get($activeSetting->resolvedBaseUrl().'/api/companies/'.$normalizedCui);

        $payload = $response->json();
        $payload = is_array($payload) ? $payload : [];

        if ($response->successful()) {
            $company = $this->extractCompanyPayload($payload);

            $cif = $this->firstNonEmptyString($company, ['cif']) ?: $normalizedCui;

            return [
                'company_name' => $this->firstNonEmptyString($company, ['denumire']),
                'company_vat_number' => $cif,
                'company_registration_number' => $this->firstNonEmptyString($company, ['numar_reg_com']),
                'address' => $this->firstNonEmptyString($company, ['adresa']),
                'city' => $this->firstNonEmptyString($company, ['localitate', 'oras', 'city']),
                'county' => $this->firstNonEmptyString($company, ['judet', 'county']),
                'source' => $company,
            ];
        }

        if ($response->status() === 202) {
            $retryAfter = $this->firstNonEmptyString($payload, ['retry_after']);
            $message = 'Compania nu este încă disponibilă în cache-ul OpenAPI.';

            if ($retryAfter !== '') {
                $message .= ' Reîncearcă după '.$retryAfter.'.';
            }

            throw new RuntimeException($message);
        }

        $apiError = $this->firstNonEmptyString($payload, [
            'error.description',
            'error.title',
            'message',
        ]);

        if ($apiError !== '') {
            throw new RuntimeException($apiError);
        }

        throw new RuntimeException(match ($response->status()) {
            400 => 'Cererea către OpenAPI este invalidă.',
            403 => 'OpenAPI a respins cererea. Verifică API key-ul.',
            404 => 'Firma nu a fost găsită pentru CUI-ul introdus.',
            429 => 'Ai depășit limita de request-uri OpenAPI.',
            default => 'Nu s-au putut prelua datele firmei din OpenAPI.',
        });
    }

    public function testConnection(?CompanyApiSetting $setting = null): void
    {
        $this->lookupByCui('13548146', $setting);
    }

    public static function normalizeCui(?string $value): string
    {
        $raw = strtoupper(trim((string) $value));
        $raw = preg_replace('/^RO/u', '', $raw) ?? $raw;
        $digits = preg_replace('/\D+/u', '', $raw) ?? '';

        return trim($digits);
    }

    private function resolveSetting(?CompanyApiSetting $setting = null): CompanyApiSetting
    {
        if ($setting instanceof CompanyApiSetting) {
            if (! $setting->is_active) {
                throw new RuntimeException('Setarea OpenAPI este inactivă.');
            }

            return $setting;
        }

        $activeSetting = CompanyApiSetting::activeOpenApi();

        if (! $activeSetting) {
            throw new RuntimeException('Setările OpenAPI nu sunt configurate. Configurează-le în Setări > API date firmă.');
        }

        return $activeSetting;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function extractCompanyPayload(array $payload): array
    {
        $data = Arr::get($payload, 'data');

        if (is_array($data) && ! array_is_list($data) && Arr::has($data, 'denumire')) {
            return $data;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $keys
     */
    private function firstNonEmptyString(array $payload, array $keys): string
    {
        foreach ($keys as $key) {
            $value = Arr::get($payload, $key);

            if (! is_scalar($value)) {
                continue;
            }

            $text = trim((string) $value);

            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }
}
