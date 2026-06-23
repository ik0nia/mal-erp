<?php

use App\Models\DiscountPolicy;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Doar dacă nu există deja politici — valori de pornire, editabile din UI.
        if (DiscountPolicy::query()->exists()) {
            return;
        }

        $starters = [
            // Consultant: 2% liber, până la 8% cu aprobare manager.
            ['role' => User::ROLE_CONSULTANT_VANZARI, 'max' => 2, 'approval' => 8, 'label' => 'Consultant vânzări (start)'],
            // Director magazin: 5% liber, până la 15% cu aprobare.
            ['role' => User::ROLE_DIRECTOR_VANZARI, 'max' => 5, 'approval' => 15, 'label' => 'Director magazin (start)'],
            // Manager: 10% liber, până la 25% cu aprobare.
            ['role' => User::ROLE_MANAGER, 'max' => 10, 'approval' => 25, 'label' => 'Manager (start)'],
        ];

        foreach ($starters as $s) {
            DiscountPolicy::create([
                'subject_type'              => DiscountPolicy::SUBJECT_ROLE,
                'role'                      => $s['role'],
                'scope_type'                => DiscountPolicy::SCOPE_ALL,
                'max_discount_percent'      => $s['max'],
                'approval_discount_percent' => $s['approval'],
                'is_active'                 => true,
                'label'                     => $s['label'],
            ]);
        }
    }

    public function down(): void
    {
        DiscountPolicy::query()->where('label', 'like', '%(start)')->delete();
    }
};
