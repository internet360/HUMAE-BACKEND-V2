<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            SalaryCurrencySeeder::class,
            CountrySeeder::class,
            StateSeeder::class,
            CompanyTaxonomySeeder::class,
            JobTaxonomySeeder::class,
            TalentTaxonomySeeder::class,
            MembershipPlanSeeder::class,
            PsychometricBigFiveSeeder::class,
            AdminUserSeeder::class,
        ]);

        // Demo data del PDF cosasfaltanteshumae (5 candidatos + 5 vacantes).
        // Solo en dev/staging; el seeder hace short-circuit en producción.
        if (! app()->environment('production')) {
            $this->call([
                PdfDemoSeeder::class,
            ]);
        }
    }
}
