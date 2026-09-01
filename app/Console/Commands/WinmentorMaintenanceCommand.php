<?php

namespace App\Console\Commands;

use App\Services\Winmentor\WinmentorBridgeClient;
use Illuminate\Console\Command;

class WinmentorMaintenanceCommand extends Command
{
    protected $signature = 'winmentor:maintenance {action : on|off|status}';

    protected $description = 'Deconectează/reconectează COM-ul MentorAPI pentru închiderea de lună (on = deconectat, off = reconectat)';

    public function handle(WinmentorBridgeClient $client): int
    {
        $action = strtolower($this->argument('action'));

        try {
            match ($action) {
                'on'     => $this->maintenanceOn($client),
                'off'    => $this->maintenanceOff($client),
                'status' => $this->status($client),
                default  => throw new \InvalidArgumentException("Acțiune necunoscută: {$action} (folosește on|off|status)"),
            };
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function maintenanceOn(WinmentorBridgeClient $client): void
    {
        $result = $client->comDisconnect();

        if (($result['data']['maintenance'] ?? false) !== true) {
            throw new \RuntimeException('Răspuns neașteptat: ' . json_encode($result));
        }

        $this->info('COM deconectat de la WinMentor — mod mentenanță ACTIV.');
        $this->line('Se poate face închiderea de lună. Reconectare: php artisan winmentor:maintenance off');
        $this->warn('Atenție: sync-urile programate vor eșua cu 503 cât timp e activ (se reiau singure).');
    }

    private function maintenanceOff(WinmentorBridgeClient $client): void
    {
        $result = $client->comConnect();

        if (($result['data']['comConnected'] ?? false) !== true) {
            throw new \RuntimeException('Reconectare eșuată: ' . json_encode($result));
        }

        $this->info('COM reconectat la WinMentor — mod mentenanță DEZACTIVAT, firma re-selectată.');
    }

    private function status(WinmentorBridgeClient $client): void
    {
        $health = $client->health();
        $data   = $health['data'] ?? [];

        $this->table(['Câmp', 'Valoare'], [
            ['status', $data['status'] ?? '?'],
            ['maintenance', ($data['maintenance'] ?? false) ? 'DA' : 'nu'],
            ['comConnected', ($data['comConnected'] ?? false) ? 'da' : 'NU'],
            ['version', $data['version'] ?? '?'],
            ['uptime', $data['uptime'] ?? '?'],
        ]);
    }
}
