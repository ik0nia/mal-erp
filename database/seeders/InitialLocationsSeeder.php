<?php

namespace Database\Seeders;

use App\Models\Location;
use Illuminate\Database\Seeder;

class InitialLocationsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Seed doar la inițializare. Dacă există deja locații, nu mai adăugăm nimic.
        if (Location::query()->exists()) {
            return;
        }

        $store = Location::updateOrCreate(
            ['name' => 'Magazin Oradea'],
            [
                'type' => Location::TYPE_STORE,
                'city' => 'Oradea',
                'is_active' => true,
            ],
        );

        Location::updateOrCreate(
            ['name' => 'Depozit principal'],
            [
                'type' => Location::TYPE_WAREHOUSE,
                'store_id' => $store->id,
                'is_active' => true,
            ],
        );
    }
}
