<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\CandidateCertification;
use App\Models\CandidateCourse;
use App\Models\CandidateEducation;
use App\Models\CandidateExperience;
use App\Models\CandidateProfile;
use App\Models\Language;
use App\Models\Skill;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * Perfil de muestra —en memoria, nunca se guarda— para la vista previa del
 * selector de plantillas.
 *
 * Un candidato que todavía no cargó nada vería tres hojas casi en blanco y no
 * podría elegir. Con datos de ejemplo ve de qué se trata cada plantilla.
 */
final class CvSampleProfile
{
    /**
     * ¿El perfil tiene tan poco cargado que la vista previa no diría nada?
     *
     * Se mide por experiencia y estudios, que son las secciones que le dan
     * cuerpo al documento. Un resumen suelto no alcanza para juzgar una
     * plantilla. Se evalúa entero o nada: mezclar entradas reales con
     * inventadas haría dudar al candidato de cuáles son suyas.
     */
    public static function isNeededFor(CandidateProfile $profile): bool
    {
        return $profile->experiences->isEmpty() && $profile->educations->isEmpty();
    }

    public static function make(): CandidateProfile
    {
        $profile = new CandidateProfile([
            'first_name' => 'Nombre',
            'last_name' => 'Apellido',
            'headline' => 'Tu puesto actual · Tu especialidad',
            'summary' => 'Acá va tu resumen profesional: en qué sos bueno, qué tipo de problemas resolvés y qué buscás en tu próximo trabajo. Dos o tres frases alcanzan.',
        ]);

        $profile->setRelation('experiences', collect([
            new CandidateExperience([
                'position_title' => 'Tu puesto',
                'company_name' => 'Empresa donde trabajás',
                'location' => 'Ciudad, País',
                'start_date' => Carbon::create(2023, 3, 1),
                'is_current' => true,
                'description' => 'Qué hacés en el puesto y qué resultados conseguiste. Los números ayudan.',
            ]),
            new CandidateExperience([
                'position_title' => 'Tu puesto anterior',
                'company_name' => 'Empresa anterior',
                'location' => 'Ciudad, País',
                'start_date' => Carbon::create(2020, 6, 1),
                'end_date' => Carbon::create(2023, 2, 1),
                'is_current' => false,
                'description' => 'Responsabilidades y logros de tu etapa anterior.',
            ]),
        ]));

        $profile->setRelation('educations', collect([
            new CandidateEducation([
                'institution' => 'Tu universidad o instituto',
                'field_of_study' => 'Tu carrera',
                'status' => 'concluido',
                'start_date' => Carbon::create(2015, 1, 1),
                'end_date' => Carbon::create(2019, 12, 1),
                'is_current' => false,
            ]),
        ]));

        $profile->setRelation('certifications', collect([
            new CandidateCertification([
                'name' => 'Tu certificación',
                'issuer' => 'Quién la emite',
                'issued_at' => Carbon::create(2022, 8, 1),
            ]),
        ]));

        $profile->setRelation('courses', collect([
            new CandidateCourse([
                'name' => 'Tu curso',
                'institution' => 'Dónde lo hiciste',
                'completed_at' => Carbon::create(2023, 5, 1),
            ]),
        ]));

        $profile->setRelation('skills', collect(
            ['Tu habilidad', 'Otra habilidad', 'Una más', 'Y otra']
        )->map(fn (string $name): Skill => self::withPivot(new Skill(['name' => $name]), 'avanzado')));

        $profile->setRelation('languages', collect([
            ['Español', 'c2'],
            ['Inglés', 'b2'],
        ])->map(fn (array $row): Language => self::withPivot(new Language(['name' => $row[0]]), $row[1])));

        return $profile;
    }

    /**
     * Las plantillas leen el nivel desde el pivote de la relación, que en un
     * modelo armado a mano no existe.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  TModel  $model
     * @return TModel
     */
    private static function withPivot($model, string $level)
    {
        $model->setRelation('pivot', new Pivot(['level' => $level]));

        return $model;
    }
}
