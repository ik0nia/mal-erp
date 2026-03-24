<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Extinde coloana la TEXT pentru a putea stoca payload-ul criptat (~300+ chars)
        Schema::table('integration_connections', function (Blueprint $table) {
            $table->text('webhook_secret')->nullable()->change();
        });

        $rows = DB::table('integration_connections')
            ->whereNotNull('webhook_secret')
            ->where('webhook_secret', '!=', '')
            ->get(['id', 'webhook_secret']);

        foreach ($rows as $row) {
            // Verificăm dacă e deja criptat de Laravel:
            // encrypt() returnează un string base64 care, decodat, e JSON cu cheile iv/value/mac
            $alreadyEncrypted = false;

            try {
                $decoded = base64_decode((string) $row->webhook_secret, strict: true);
                if ($decoded !== false) {
                    $payload = json_decode($decoded, associative: true);
                    if (is_array($payload) && isset($payload['iv'], $payload['value'], $payload['mac'])) {
                        $alreadyEncrypted = true;
                    }
                }
            } catch (\Throwable) {
                // nu e criptat
            }

            if ($alreadyEncrypted) {
                continue;
            }

            DB::table('integration_connections')
                ->where('id', $row->id)
                ->update(['webhook_secret' => encrypt($row->webhook_secret)]);
        }
    }

    public function down(): void
    {
        // Nu decriptăm la rollback — păstrăm securitatea datelor
        // Coloana rămâne TEXT (upgrade non-destructiv)
    }
};
