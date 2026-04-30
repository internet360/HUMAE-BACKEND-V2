<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\CandidateKind;
use App\Enums\CandidateState;
use App\Enums\MembershipStatus;
use App\Enums\UserRole;
use App\Enums\VacancyState;
use App\Enums\VacancyTargetKind;
use App\Models\CandidateProfile;
use App\Models\Company;
use App\Models\FunctionalArea;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\User;
use App\Models\Vacancy;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Demo seeder específico para los puntos del PDF cosasfaltanteshumae.pdf:
 * 5 candidatos (mix empleado/practicante en distintas áreas) y 5 vacantes
 * (mix kinds) para que el cliente pueda probar de inmediato:
 *   - Filtro por categoría empleado/practicante en el directorio.
 *   - Filtro multi-área en el directorio.
 *   - Sugerencias de candidatos en una vacante (panel de matching).
 *
 * Idempotente vía updateOrCreate. NO correr en producción.
 */
class PdfDemoSeeder extends Seeder
{
    private const PASSWORD = 'Password123';

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command->warn('PdfDemoSeeder: saltado en producción.');

            return;
        }

        $plan = MembershipPlan::where('code', 'candidate_6m')->first();
        if ($plan === null) {
            $this->command->error('PdfDemoSeeder: falta MembershipPlan candidate_6m. Corre MembershipPlanSeeder primero.');

            return;
        }

        $area = function (string $code): FunctionalArea {
            $a = FunctionalArea::where('code', $code)->first();
            if ($a === null) {
                throw new \RuntimeException("FunctionalArea '$code' no encontrada. Corre JobTaxonomySeeder.");
            }

            return $a;
        };

        $produccion = $area('manufacturing');
        $calidad = $area('quality');
        $mantenimiento = $area('maintenance');
        $logistica = $area('logistics');
        $almacen = $area('warehouse');
        $ingenieria = $area('engineering');
        $sistemas = $area('it_systems');
        $rh = $area('hr');

        // -------- 5 candidatos --------

        $candidates = [
            [
                'email' => 'pablo.intern@demo.humae',
                'name' => 'Pablo Sánchez',
                'kind' => CandidateKind::Intern,
                'years' => 0,
                'salary' => 6000,
                'areas' => [
                    ['area' => $ingenieria, 'is_primary' => true],
                    ['area' => $produccion, 'is_primary' => false],
                ],
            ],
            [
                'email' => 'maria.intern@demo.humae',
                'name' => 'María Torres',
                'kind' => CandidateKind::Intern,
                'years' => 1,
                'salary' => 7000,
                'areas' => [
                    ['area' => $sistemas, 'is_primary' => true],
                ],
            ],
            [
                'email' => 'juan.empleado@demo.humae',
                'name' => 'Juan Ramírez',
                'kind' => CandidateKind::Employee,
                'years' => 4,
                'salary' => 18000,
                'areas' => [
                    ['area' => $produccion, 'is_primary' => true],
                    ['area' => $calidad, 'is_primary' => false],
                    ['area' => $mantenimiento, 'is_primary' => false],
                ],
            ],
            [
                'email' => 'sofia.empleado@demo.humae',
                'name' => 'Sofía Méndez',
                'kind' => CandidateKind::Employee,
                'years' => 6,
                'salary' => 25000,
                'areas' => [
                    ['area' => $logistica, 'is_primary' => true],
                    ['area' => $almacen, 'is_primary' => false],
                ],
            ],
            [
                'email' => 'lucia.empleado@demo.humae',
                'name' => 'Lucía Hernández',
                'kind' => CandidateKind::Employee,
                'years' => 8,
                'salary' => 35000,
                'areas' => [
                    ['area' => $rh, 'is_primary' => true],
                ],
            ],
        ];

        foreach ($candidates as $data) {
            $user = User::updateOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'password' => Hash::make(self::PASSWORD),
                    'email_verified_at' => now(),
                    'status' => 'active',
                ],
            );
            if (! $user->hasRole(UserRole::Candidate->value)) {
                $user->assignRole(UserRole::Candidate->value);
            }

            Membership::updateOrCreate(
                ['user_id' => $user->id, 'membership_plan_id' => $plan->id],
                [
                    'status' => MembershipStatus::Active,
                    'started_at' => now()->subDays(30),
                    'expires_at' => now()->addDays(150),
                ],
            );

            $names = explode(' ', $data['name'], 2);
            $profile = CandidateProfile::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'first_name' => $names[0],
                    'last_name' => $names[1] ?? '',
                    'headline' => $data['kind'] === CandidateKind::Intern
                        ? 'Practicante con interés en '.$data['areas'][0]['area']->name
                        : 'Profesional con '.$data['years'].' años en '.$data['areas'][0]['area']->name,
                    'years_of_experience' => $data['years'],
                    'expected_salary_min' => $data['salary'],
                    'expected_salary_max' => $data['salary'] * 1.5,
                    'expected_salary_period' => 'mes',
                    'candidate_kind' => $data['kind'],
                    'state' => CandidateState::Activo,
                ],
            );

            $sync = [];
            $sort = 0;
            foreach ($data['areas'] as $a) {
                $sync[$a['area']->id] = [
                    'is_primary' => $a['is_primary'],
                    'sort_order' => $sort++,
                ];
            }
            $profile->functionalAreas()->sync($sync);

            // Sync legacy single FK con la primaria.
            $primary = collect($data['areas'])->firstWhere('is_primary', true);
            if ($primary !== null && $profile->functional_area_id !== $primary['area']->id) {
                $profile->forceFill(['functional_area_id' => $primary['area']->id])->save();
            }
        }

        // -------- Empresa demo + 5 vacantes --------

        $company = Company::firstOrCreate(
            ['slug' => 'humae-demo-corp'],
            [
                'legal_name' => 'Humae Demo S.A. de C.V.',
                'trade_name' => 'Humae Demo',
                'description' => 'Empresa de demostración generada por PdfDemoSeeder.',
                'contact_name' => 'Humae Demo',
                'contact_email' => 'demo@humae.com.mx',
                'contact_phone' => '+52 55 0000 0001',
                'status' => 'active',
                'is_verified' => true,
            ],
        );

        $vacancies = [
            [
                'title' => 'Practicante de Ingeniería Industrial',
                'kind' => VacancyTargetKind::Intern,
                'area' => $produccion,
                'min_years' => 0,
                'max_years' => 1,
                'salary_max' => 8000,
            ],
            [
                'title' => 'Practicante de Sistemas',
                'kind' => VacancyTargetKind::Intern,
                'area' => $sistemas,
                'min_years' => 0,
                'max_years' => 1,
                'salary_max' => 9000,
            ],
            [
                'title' => 'Auxiliar de Almacén',
                'kind' => VacancyTargetKind::Employee,
                'area' => $almacen,
                'min_years' => 1,
                'max_years' => 5,
                'salary_max' => 18000,
            ],
            [
                'title' => 'Coordinador de Calidad',
                'kind' => VacancyTargetKind::Employee,
                'area' => $calidad,
                'min_years' => 3,
                'max_years' => 8,
                'salary_max' => 30000,
            ],
            [
                'title' => 'Generalista de RH',
                'kind' => VacancyTargetKind::Any,
                'area' => $rh,
                'min_years' => 2,
                'max_years' => 10,
                'salary_max' => 32000,
            ],
        ];

        foreach ($vacancies as $i => $v) {
            Vacancy::updateOrCreate(
                ['code' => 'HUM-DEMO-'.str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT)],
                [
                    'company_id' => $company->id,
                    'title' => $v['title'],
                    'slug' => Str::slug($v['title']).'-demo',
                    'description' => $v['title'].' (vacante demo del PDF cosasfaltanteshumae).',
                    'target_candidate_kind' => $v['kind'],
                    'functional_area_id' => $v['area']->id,
                    'min_years_of_experience' => $v['min_years'],
                    'max_years_of_experience' => $v['max_years'],
                    'salary_max' => $v['salary_max'],
                    'salary_period' => 'mes',
                    'state' => VacancyState::Activa,
                    'published_at' => now(),
                ],
            );
        }

        $this->command->info('PdfDemoSeeder: 5 candidatos + 5 vacantes demo creados.');
        $this->command->info('  Login candidato (cualquiera): password "'.self::PASSWORD.'"');
        $this->command->info('    - pablo.intern@demo.humae       (Practicante · Ingeniería + Producción)');
        $this->command->info('    - maria.intern@demo.humae       (Practicante · Sistemas)');
        $this->command->info('    - juan.empleado@demo.humae      (Empleado · Producción/Calidad/Mantenimiento)');
        $this->command->info('    - sofia.empleado@demo.humae     (Empleado · Logística + Almacén)');
        $this->command->info('    - lucia.empleado@demo.humae     (Empleado · RH)');
    }
}
