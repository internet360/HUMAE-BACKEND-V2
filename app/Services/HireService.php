<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AssignmentStage;
use App\Enums\VacancyState;
use App\Models\User;
use App\Models\Vacancy;
use App\Models\VacancyAssignment;
use App\Notifications\AssignmentRejectedNotification;
use App\Notifications\CandidateHiredNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use RuntimeException;

/**
 * Cierra el ciclo de una vacante: al marcar una asignación como `hired`,
 * mueve la vacante a `cubierta`, retira automáticamente el resto de
 * asignaciones activas y notifica a todas las partes.
 */
class HireService
{
    /**
     * Stages de asignaciones que deben auto-retirarse al contratar a otro.
     *
     * @var list<string>
     */
    private const ACTIVE_STAGES = [
        'sourced',
        'presented',
        'interviewing',
        'finalist',
    ];

    private const AUTO_WITHDRAW_REASON = 'Vacante cubierta por otro candidato';

    public function hire(VacancyAssignment $assignment): VacancyAssignment
    {
        $vacancy = $assignment->vacancy;
        if ($vacancy === null) {
            throw new RuntimeException('La asignación no está vinculada a una vacante.');
        }

        $fromStage = $assignment->stage ?? AssignmentStage::Sourced;
        if (! AssignmentStageMachine::canTransition($fromStage, AssignmentStage::Hired)) {
            throw new RuntimeException(
                "Transición inválida: {$fromStage->value} → hired",
            );
        }

        return DB::transaction(function () use ($assignment, $vacancy): VacancyAssignment {
            // 1) Marcar el assignment como hired.
            $assignment->forceFill([
                'stage' => AssignmentStage::Hired->value,
                'hired_at' => now(),
            ])->save();

            // 2) Cerrar la vacante.
            $vacancy->forceFill([
                'state' => VacancyState::Cubierta->value,
                'filled_at' => now(),
            ])->save();

            // 3) Auto-withdraw del resto de asignaciones activas.
            $otherAssignments = VacancyAssignment::query()
                ->where('vacancy_id', $vacancy->id)
                ->where('id', '!=', $assignment->id)
                ->whereIn('stage', self::ACTIVE_STAGES)
                ->with('candidateProfile.user', 'vacancy')
                ->get();

            foreach ($otherAssignments as $other) {
                $other->forceFill([
                    'stage' => AssignmentStage::Withdrawn->value,
                    'withdrawn_at' => now(),
                    'rejection_reason' => self::AUTO_WITHDRAW_REASON,
                ])->save();
            }

            // 4) Notificar.
            $assignment->load('candidateProfile.user', 'vacancy.company');

            $hiredUser = $assignment->candidateProfile?->user;
            if ($hiredUser !== null) {
                $hiredUser->notify(new CandidateHiredNotification($assignment));
            }

            $companyUsers = $this->companyRecipients($vacancy);
            if ($companyUsers->isNotEmpty()) {
                Notification::send($companyUsers, new CandidateHiredNotification($assignment));
            }

            foreach ($otherAssignments as $other) {
                $user = $other->candidateProfile?->user;
                if ($user !== null) {
                    $user->notify(new AssignmentRejectedNotification(
                        $other,
                        self::AUTO_WITHDRAW_REASON,
                    ));
                }
            }

            return $assignment->fresh(['candidateProfile.user', 'vacancy']) ?? $assignment;
        });
    }

    /**
     * @return Collection<int, User>
     */
    private function companyRecipients(Vacancy $vacancy): Collection
    {
        return $vacancy->company
            ?->members()
            ->with('user')
            ->whereIn('role', ['owner', 'manager'])
            ->get()
            ->pluck('user')
            ->filter()
            ->values() ?? collect();
    }
}
