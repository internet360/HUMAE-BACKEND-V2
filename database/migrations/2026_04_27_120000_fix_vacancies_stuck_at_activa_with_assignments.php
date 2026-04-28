<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Repara vacantes que se quedaron en `activa` o `en_busqueda` aunque ya tenían
 * asignaciones de candidatos. Antes, PipelineService::assign solo avanzaba la
 * vacante desde `en_busqueda` → `con_candidatos_asignados`, por lo que las
 * creadas estando `activa` quedaban estancadas y el InterviewService rechazaba
 * las propuestas de entrevista.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('vacancies')
            ->whereIn('state', ['activa', 'en_busqueda'])
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('vacancy_assignments')
                    ->whereColumn('vacancy_assignments.vacancy_id', 'vacancies.id')
                    ->whereNull('vacancy_assignments.deleted_at');
            })
            ->update(['state' => 'con_candidatos_asignados']);
    }

    public function down(): void
    {
        // No reversamos: el estado correcto a partir de existir asignaciones
        // es `con_candidatos_asignados` o posterior. Volver a `activa` sería
        // re-introducir el bug.
    }
};
