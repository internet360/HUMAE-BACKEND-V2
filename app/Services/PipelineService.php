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

            // Si la vacante estaba en `en_busqueda`, avanza el estado global.
            if ($vacancy->state === VacancyState::EnBusqueda) {
                $vacancy->forceFill([
                    'state' => VacancyState::ConCandidatosAsignados->value,
                ])->save();
            }

            return $assignment;
        });
    }

    public function changeStage(
        VacancyAssignment $assignment,
        AssignmentStage $to,
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

        // El cierre de vacante (→ hired) es transaccional + notifica a todas
        // las partes. Delegamos en HireService.
        if ($to === AssignmentStage::Hired) {
            return $this->hireService->hire($assignment);
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
