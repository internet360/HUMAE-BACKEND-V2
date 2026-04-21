<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\MembershipPlan;
use App\Models\SalaryCurrency;
use Illuminate\Database\Seeder;
use RuntimeException;

class MembershipPlanSeeder extends Seeder
{
    public function run(): void
    {
        $mxn = SalaryCurrency::where('code', 'MXN')->first();

        if ($mxn === null) {
            throw new RuntimeException('Missing MXN currency. Run SalaryCurrencySeeder first.');
        }

        MembershipPlan::updateOrCreate(
            ['code' => 'candidate_6m'],
            [
                'name' => 'Membresía Candidato · 6 meses',
                'description' => 'Acceso completo al perfil HUMAE, pruebas psicométricas y generación de CV por 6 meses.',
                'salary_currency_id' => $mxn->id,
                'price' => 499.00,
                'duration_days' => 180,
                'stripe_price_id' => null,
                'stripe_product_id' => null,
                'is_active' => true,
                'sort_order' => 1,
            ]
        );
    }
}
