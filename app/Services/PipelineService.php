<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AssignmentStage;
use App\Enums\MembershipStatus;
use App\Enums\VacancyState;
use App\Models\CandidateProfile;
use App\Models\User;
use App\Models\Vacancy;
use App\Models\VacancyAssignment;
use App\Notifications\AssignmentStageChangedNotification;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PipelineService
{
    public function __construct(
        private readonly HireService $hireService,
    ) {}

    /**
     * Estados de la vacante que permiten aceptar nuevos assignments.
     *
     * @var list<string>
     */
    private const VACANCY_ACCEPTS_ASSIGNMENTS = [
        'activa',
        'en_busqueda',
        // Flujo del empleador: la vacante nace en `solicitada` con los perfiles
        // ya señalados por el cliente. Sin esta entrada, aceptar un perfil de la
        // solicitud fallaría por estado y el flujo se cortaría en su primer paso.
        'solicitada',
        'con_candidatos_asignados',
        'entrevistas_en_curso',
    ];

    public function assign(
        Vacancy $vacancy,
        CandidateProfile $candidate,
        User $assignedBy,
    ): VacancyAssignment {
        $this->validateAssignable($vacancy, $candidate);

        $existing = VacancyAssignment::where('vacancy_id', $vacancy->id)
            ->where('candidate_profile_id', $candidate->id)
            ->first();

        if ($existing !== null) {
            throw new RuntimeException('Este candidato ya está asignado a la vacante.');
        }

        return DB::transaction(function () use ($vacancy, $candidate, $assignedBy): VacancyAssignment {
            $assignment = VacancyAssignment::create([
                'vacancy_id' => $vacancy->id,
                'candidate_profile_id' => $candidate->id,
                'assigned_by' => $assignedBy->id,
                'stage' => AssignmentStage::Sourced->value,
                'presented_at' => null,
            ]);

            // Avanzar la vacante a `con_candidatos_asignados` apenas se crea
            // la primera asignación. Acepta venir desde `activa`, `en_busqueda`
            // o `solicitada` (las tres son estados pre-pipeline donde aún no
            // había candidatos).
            if (
                $vacancy->state === VacancyState::Activa
                || $vacancy->state === VacancyState::EnBusqueda
                || $vacancy->state === VacancyState::Solicitada
            ) {
                $vacancy->forceFill([
                    'state' => VacancyState::ConCandidatosAsignados->value,
                ])->save();
            }

            return $assignment;
        });
    }

    /**
     * @param  User|null  $actor  obligatorio sólo para cerrar la colocación
     *                            (→ hired), donde el cargo devengado queda
     *                            firmado por quien la cerró.
     */
    public function changeStage(
        VacancyAssignment $assignment,
        AssignmentStage $to,
        ?User $actor = null,
    ): VacancyAssignment {
        $from = $assignment->stage;
        if ($from === null) {
            $from = AssignmentStage::Sourced;
        }

        if (! AssignmentStageMachine::canTransition($from, $to)) {
            throw new RuntimeException(
                "Transición inválida: {$from->value} → {$to->value}",
            );
        }

        // El cierre de vacante (→ hired) es transaccional, devenga honorarios y
        // notifica a todas las partes. Delegamos en HireService.
        if ($to === AssignmentStage::Hired) {
            if ($actor === null) {
                throw new RuntimeException(
                    'Cerrar una colocación exige identificar quién la cierra.',
                );
            }

            return $this->hireService->hire($assignment, $actor);
        }

        $payload = ['stage' => $to->value];

        foreach (AssignmentStageMachine::timestampField($to) as $field => $_) {
            $payload[$field] = now();
        }

        $assignment->forceFill($payload)->save();

        // Notificar al candidato
        $candidateUser = $assignment->candidateProfile?->user;
        if ($candidateUser !== null) {
            $candidateUser->notify(new AssignmentStageChangedNotification($assignment, $to));
        }

        return $assignment->fresh() ?? $assignment;
    }

    /**
     * El company_user marca un candidato como finalista (stage=finalist).
     */
    public function selectFinalist(VacancyAssignment $assignment): VacancyAssignment
    {
        return $this->changeStage($assignment, AssignmentStage::Finalist);
    }

    /**
     * @throws RuntimeException
     */
    private function validateAssignable(Vacancy $vacancy, CandidateProfile $candidate): void
    {
        $state = $vacancy->state?->value;
        if ($state === null || ! in_array($state, self::VACANCY_ACCEPTS_ASSIGNMENTS, true)) {
            throw new RuntimeException(
                'La vacante no acepta asignaciones en su estado actual.',
            );
        }

        $candidateUser = $candidate->user;
        if ($candidateUser === null) {
            throw new RuntimeException('El candidato no tiene usuario asociado.');
        }

        $hasActiveMembership = $candidateUser->memberships()
            ->where('status', MembershipStatus::Active->value)
            ->where('expires_at', '>', now())
            ->exists();

        if (! $hasActiveMembership) {
            throw new RuntimeException(
                'Sólo candidatos con membresía activa pueden asignarse a vacantes.',
            );
        }
    }
}
