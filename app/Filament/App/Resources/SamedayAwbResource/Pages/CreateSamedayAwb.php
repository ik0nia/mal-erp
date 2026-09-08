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
            'recipient_street'      => request()->string('recipient_address')->toString(),
            'recipient_postal_code' => request()->string('recipient_postal_code')->toString(),
            'cod_amount'            => request()->string('cod_amount')->toString() ?: null,
            'reference'             => request()->string('reference')->toString(),
            'locker_last_mile'      => request()->integer('locker_last_mile') ?: null,
        ]);

        // Mapăm județul (cod ISO Woo, ex. "CJ") și orașul (text) pe nomenclatorul Sameday
        $countyId = $this->resolveCountyId(request()->string('recipient_county')->toString());
        if ($countyId) {
            $prefill['recipient_county_id'] = $countyId;
            $cityId = $this->resolveCityId($countyId, request()->string('recipient_city')->toString());
            if ($cityId) {
                $prefill['recipient_city_id'] = $cityId;
            }
        }

        if (! empty($prefill)) {
            // Merge peste defaults (fill() cu array parțial ar goli restul câmpurilor)
            $this->form->fill(array_merge($this->form->getRawState(), $prefill));
        }
    }

    /** Cod ISO Woo (ex. "CJ") sau nume → ID județ Sameday. */
    private function resolveCountyId(string $county): ?int
    {
        $county = trim($county);
        if ($county === '') {
            return null;
        }

        $isoToName = [
            'AB' => 'Alba', 'AR' => 'Arad', 'AG' => 'Arges', 'BC' => 'Bacau', 'BH' => 'Bihor',
            'BN' => 'Bistrita-Nasaud', 'BT' => 'Botosani', 'BV' => 'Brasov', 'BR' => 'Braila',
            'B' => 'Bucuresti', 'BZ' => 'Buzau', 'CS' => 'Caras-Severin', 'CL' => 'Calarasi',
            'CJ' => 'Cluj', 'CT' => 'Constanta', 'CV' => 'Covasna', 'DB' => 'Dambovita',
            'DJ' => 'Dolj', 'GL' => 'Galati', 'GR' => 'Giurgiu', 'GJ' => 'Gorj', 'HR' => 'Harghita',
            'HD' => 'Hunedoara', 'IL' => 'Ialomita', 'IS' => 'Iasi', 'IF' => 'Ilfov',
            'MM' => 'Maramures', 'MH' => 'Mehedinti', 'MS' => 'Mures', 'NT' => 'Neamt',
            'OT' => 'Olt', 'PH' => 'Prahova', 'SM' => 'Satu Mare', 'SJ' => 'Salaj', 'SB' => 'Sibiu',
            'SV' => 'Suceava', 'TR' => 'Teleorman', 'TM' => 'Timis', 'TL' => 'Tulcea',
            'VS' => 'Vaslui', 'VL' => 'Valcea', 'VN' => 'Vrancea',
        ];

        $name = $isoToName[strtoupper($county)] ?? $county;
        $needle = $this->normalize($name);

        foreach (SamedayAwbResource::countyOptionsForCurrentUserLocation() as $id => $label) {
            if ($this->normalize($label) === $needle) {
                return (int) $id;
            }
        }

        return null;
    }

    /** Nume oraș din comandă → ID oraș Sameday (match exact normalizat, apoi prefix). */
    private function resolveCityId(int $countyId, string $city): ?int
    {
        $needle = $this->normalize($city);
        if ($needle === '') {
            return null;
        }

        $options = SamedayAwbResource::cityOptionsForCurrentUserLocation($countyId);

        // București: "Sector 3" / "Bucuresti Sectorul 3" → "Sectorul 3"
        if (preg_match('/sector(?:ul)?\s*(\d)/', $needle, $m)) {
            foreach ($options as $id => $label) {
                if (str_contains($this->normalize($label), 'sectorul '.$m[1])) {
                    return (int) $id;
                }
            }
        }

        foreach ($options as $id => $label) {
            if ($this->normalize($label) === $needle) {
                return (int) $id;
            }
        }

        foreach ($options as $id => $label) {
            $norm = $this->normalize($label);
            if (str_starts_with($norm, $needle) || str_starts_with($needle, $norm)) {
                return (int) $id;
            }
        }

        return null;
    }

    /** Lowercase + fără diacritice, pentru comparat nume geografice. */
    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = strtr($value, ['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't']);

        return preg_replace('/[^a-z0-9 -]/', '', $value) ?? '';
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

        $order = $this->wooOrderId ? WooOrder::find($this->wooOrderId) : null;

        // Fluxul complet (creare Sameday + persistare + oglindire site + notă/meta Woo)
        return app(\App\Services\Courier\SamedayAwbCreator::class)->create($data, $user, $order);
    }

    protected function afterCreate(): void
    {
        // Oglindirea pe site + nota/meta Woo sunt făcute de SamedayAwbCreator.
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
