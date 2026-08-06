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
            // Después de JobTaxonomySeeder: resuelve functional_area_id contra
            // las áreas que ese seeder crea.
            PositionSeeder::class,
            TalentTaxonomySeeder::class,
            MembershipPlanSeeder::class,
            PsychometricBigFiveSeeder::class,
            AdminUserSeeder::class,
        ]);

        // Demo data del PDF cosasfaltanteshumae (5 candidatos + 5 vacantes) +
        // usuarios de prueba + datos relacionales (pipeline, entrevistas,
        // psicométricos, notificaciones). Solo en dev/staging; cada seeder
        // hace short-circuit en producción. Orden importa: TestAccountsSeeder
        // crea el reclutador/usuario de empresa que DemoRelationalSeeder
        // necesita, y debe correr después de PdfDemoSeeder (que crea las
        // vacantes).
        if (! app()->environment('production')) {
            $this->call([
                PdfDemoSeeder::class,
                TestAccountsSeeder::class,
                DemoRelationalSeeder::class,
            ]);
        }
    }
}
