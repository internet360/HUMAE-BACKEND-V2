<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\LanguageLevel;
use App\Enums\SkillLevel;
use App\Models\CandidateCertification;
use App\Models\CandidateCourse;
use App\Models\CandidateEducation;
use App\Models\CandidateExperience;
use App\Models\CandidateProfile;
use App\Models\Language;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Carga la trayectoria de un candidato demo: experiencia, estudios,
 * certificaciones, cursos, habilidades e idiomas.
 *
 * Los demás seeders demo crean perfiles con encabezado y expectativa salarial
 * pero sin historial, así que el CV en PDF salía prácticamente en blanco y no
 * servía para revisar las plantillas.
 *
 * Es idempotente: reemplaza el historial del perfil en cada corrida.
 */
class CandidateCvDemoSeeder extends Seeder
{
    private const EMAIL = 'juan.empleado@demo.humae';

    public function run(): void
    {
        if (app()->environment('production')) {
            return;
        }

        $user = User::where('email', self::EMAIL)->first();

        if ($user === null) {
            $this->command->warn('  CandidateCvDemoSeeder: no existe '.self::EMAIL.'. Corre PdfDemoSeeder primero.');

            return;
        }

        $profile = CandidateProfile::where('user_id', $user->id)->first();

        if ($profile === null) {
            $this->command->warn('  CandidateCvDemoSeeder: '.self::EMAIL.' no tiene perfil de candidato.');

            return;
        }

        $profile->update([
            'summary' => 'Técnico de producción con cuatro años en piso de manufactura. '
                .'Opero líneas de ensamble, levanto y cierro no conformidades de calidad, '
                .'y ejecuto rutinas de mantenimiento preventivo. Me formé entre producción, '
                .'calidad y mantenimiento, así que entiendo dónde se traba una línea antes '
                .'de que se detenga.',
            'contact_email' => self::EMAIL,
            'contact_phone' => '+52 81 2345 6789',
            'linkedin_url' => 'https://www.linkedin.com/in/juan-ramirez-produccion',
        ]);

        $this->replaceExperiences($profile);
        $this->replaceEducations($profile);
        $this->replaceCertifications($profile);
        $this->replaceCourses($profile);
        $this->syncSkills($profile);
        $this->syncLanguages($profile);

        $this->command->info('  CV demo cargado para '.self::EMAIL.'.');
    }

    private function replaceExperiences(CandidateProfile $profile): void
    {
        $profile->experiences()->delete();

        // Fechas relativas para que la trayectoria siga cuadrando con los
        // años de experiencia del perfil sin importar cuándo se corra.
        $now = Carbon::now()->startOfMonth();

        $rows = [
            [
                'position_title' => 'Técnico de Producción',
                'company_name' => 'Grupo Industrial Monterrey',
                'location' => 'Apodaca, Nuevo León',
                'start_date' => $now->copy()->subMonths(29),
                'end_date' => null,
                'is_current' => true,
                'description' => 'Superviso dos líneas de ensamble en turno mixto. Reduje el paro no '
                    .'programado de 6 a 2 horas por semana ajustando la rutina de arranque y '
                    .'adelantando el cambio de herramentales.',
                'achievements' => 'Cero accidentes registrados en 18 meses de operación.',
                'sort_order' => 0,
            ],
            [
                'position_title' => 'Auxiliar de Control de Calidad',
                'company_name' => 'Manufacturas del Bajío',
                'location' => 'Silao, Guanajuato',
                'start_date' => $now->copy()->subMonths(38),
                'end_date' => $now->copy()->subMonths(30),
                'is_current' => false,
                'description' => 'Inspección dimensional por muestreo y levantamiento de no '
                    .'conformidades. Armé el formato de rechazo que después se estandarizó '
                    .'para las tres líneas de la planta.',
                'achievements' => null,
                'sort_order' => 1,
            ],
            [
                'position_title' => 'Operador de Línea',
                'company_name' => 'Metalúrgica Toluca',
                'location' => 'Toluca, Estado de México',
                'start_date' => $now->copy()->subMonths(47),
                'end_date' => $now->copy()->subMonths(39),
                'is_current' => false,
                'description' => 'Operación de prensa y troquelado con registro de producción por turno.',
                'achievements' => null,
                'sort_order' => 2,
            ],
        ];

        foreach ($rows as $row) {
            CandidateExperience::create([...$row, 'candidate_profile_id' => $profile->id]);
        }
    }

    private function replaceEducations(CandidateProfile $profile): void
    {
        $profile->educations()->delete();

        $rows = [
            [
                'institution' => 'Universidad Tecnológica de Nuevo León',
                'field_of_study' => 'TSU en Procesos Industriales',
                'location' => 'Guadalupe, Nuevo León',
                'start_date' => Carbon::create(2019, 9, 1),
                'end_date' => Carbon::create(2021, 12, 1),
                'is_current' => false,
                'status' => 'concluido',
                'sort_order' => 0,
            ],
            [
                'institution' => 'CBTis 99',
                'field_of_study' => 'Técnico en Mantenimiento Industrial',
                'location' => 'Monterrey, Nuevo León',
                'start_date' => Carbon::create(2016, 8, 1),
                'end_date' => Carbon::create(2019, 6, 1),
                'is_current' => false,
                'status' => 'titulado',
                'sort_order' => 1,
            ],
        ];

        foreach ($rows as $row) {
            CandidateEducation::create([...$row, 'candidate_profile_id' => $profile->id]);
        }
    }

    private function replaceCertifications(CandidateProfile $profile): void
    {
        $profile->certifications()->delete();

        $rows = [
            [
                'name' => 'Green Belt Lean Six Sigma',
                'issuer' => 'ASQ México',
                'issued_at' => Carbon::now()->startOfMonth()->subMonths(14),
                'sort_order' => 0,
            ],
            [
                'name' => 'Seguridad industrial NOM-STPS',
                'issuer' => 'Secretaría del Trabajo y Previsión Social',
                'issued_at' => Carbon::now()->startOfMonth()->subMonths(26),
                'sort_order' => 1,
            ],
        ];

        foreach ($rows as $row) {
            CandidateCertification::create([...$row, 'candidate_profile_id' => $profile->id]);
        }
    }

    private function replaceCourses(CandidateProfile $profile): void
    {
        $profile->courses()->delete();

        $rows = [
            [
                'name' => 'Control estadístico de procesos (SPC)',
                'institution' => 'Tecnológico de Monterrey · Educación Continua',
                'duration_hours' => 40,
                'completed_at' => Carbon::now()->startOfMonth()->subMonths(9),
                'sort_order' => 0,
            ],
            [
                'name' => 'Mantenimiento Productivo Total (TPM)',
                'institution' => 'CONALEP',
                'duration_hours' => 24,
                'completed_at' => Carbon::now()->startOfMonth()->subMonths(20),
                'sort_order' => 1,
            ],
        ];

        foreach ($rows as $row) {
            CandidateCourse::create([...$row, 'candidate_profile_id' => $profile->id]);
        }
    }

    private function syncSkills(CandidateProfile $profile): void
    {
        // El catálogo base es de perfiles de oficina; estas son de piso de
        // planta. updateOrCreate para no duplicarlas entre corridas.
        $shopFloor = [
            'lean_manufacturing' => 'Lean Manufacturing',
            'spc' => 'Control estadístico de proceso',
            'five_s' => 'Metodología 5S',
            'preventive_maintenance' => 'Mantenimiento preventivo',
            'industrial_safety' => 'Seguridad industrial',
        ];

        foreach ($shopFloor as $code => $name) {
            Skill::updateOrCreate(['code' => $code], ['name' => $name, 'category' => 'tecnica']);
        }

        $levels = [
            'lean_manufacturing' => SkillLevel::Intermedio,
            'spc' => SkillLevel::Avanzado,
            'five_s' => SkillLevel::Avanzado,
            'preventive_maintenance' => SkillLevel::Avanzado,
            'industrial_safety' => SkillLevel::Avanzado,
            'excel' => SkillLevel::Intermedio,
            'sap' => SkillLevel::Basico,
            'teamwork' => SkillLevel::Avanzado,
            'problem_solving' => SkillLevel::Avanzado,
        ];

        $sync = [];

        foreach ($levels as $code => $level) {
            $skill = Skill::where('code', $code)->first();

            if ($skill !== null) {
                $sync[$skill->id] = ['level' => $level->value];
            }
        }

        $profile->skills()->sync($sync);
    }

    private function syncLanguages(CandidateProfile $profile): void
    {
        $levels = [
            'es' => LanguageLevel::Nativo,
            'en' => LanguageLevel::B1,
        ];

        $sync = [];

        foreach ($levels as $code => $level) {
            $language = Language::where('code', $code)->first();

            if ($language !== null) {
                $sync[$language->id] = ['level' => $level->value];
            }
        }

        $profile->languages()->sync($sync);
    }
}
