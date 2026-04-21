<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\InterviewMode;
use App\Enums\InterviewState;
use App\Enums\VacancyState;
use App\Models\Interview;
use App\Models\InterviewReschedule;
use App\Models\User;
use App\Models\VacancyAssignment;
use App\Notifications\InterviewCancelledNotification;
use App\Notifications\InterviewConfirmedNotification;
use App\Notifications\InterviewScheduledNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use RuntimeException;

class InterviewService
{
    /**
     * Estados en los que puede proponerse una nueva entrevista.
     *
     * @var list<string>
     */
    private const VACANCY_ACCEPTS_INTERVIEWS = [
        'con_candidatos_asignados',
        'entrevistas_en_curso',
    ];

    /**
     * @param  array<string, mixed>  $data
     */
    public function schedule(
        VacancyAssignment $assignment,
        User $scheduledBy,
        array $data,
    ): Interview {
        $vacancy = $assignment->vacancy;
        if ($vacancy === null) {
            throw new RuntimeException('La asignación no está vinculada a una vacante.');
        }

        $state = $vacancy->state?->value;
        if ($state === null || ! in_array($state, self::VACANCY_ACCEPTS_INTERVIEWS, true)) {
            throw new RuntimeException(
                'La vacante no está en un estado que admita entrevistas.',
            );
        }

        return DB::transaction(function () use ($assignment, $scheduledBy, $data, $vacancy): Interview {
            $round = (int) ($data['round'] ?? ($assignment->interviews()->max('round') ?? 0) + 1);

            $interview = $assignment->interviews()->create([
                'scheduled_by' => $scheduledBy->id,
                'round' => $round,
                'title' => $data['title'] ?? null,
                'state' => InterviewState::Propuesta->value,
                'mode' => $data['mode'] ?? InterviewMode::Online->value,
                'scheduled_at' => Carbon::parse((string) $data['scheduled_at']),
                'duration_minutes' => (int) ($data['duration_minutes'] ?? 60),
                'timezone' => $data['timezone'] ?? 'America/Mexico_City',
                'meeting_url' => $data['meeting_url'] ?? null,
                'meeting_provider' => $data['meeting_provider'] ?? null,
                'meeting_id' => $data['meeting_id'] ?? null,
                'location' => $data['location'] ?? null,
            ]);

            // Avanza la vacancy a `entrevistas_en_curso` en su primera entrevista.
            if ($vacancy->state === VacancyState::ConCandidatosAsignados) {
                $vacancy->forceFill([
                    'state' => VacancyState::EntrevistasEnCurso->value,
                ])->save();
            }

            $this->notifyParties($interview, new InterviewScheduledNotification($interview));

            return $interview;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function reschedule(
        Interview $interview,
        User $actor,
        Carbon $newScheduledAt,
        ?string $reason = null,
        array $data = [],
    ): Interview {
        $previous = $interview->scheduled_at;
        if ($previous === null) {
            throw new RuntimeException('La entrevista no tiene fecha original.');
        }

        $currentState = $interview->state ?? InterviewState::Propuesta;
        if (! InterviewStateMachine::canTransition($currentState, InterviewState::Reprogramada)) {
            throw new RuntimeException('La entrevista no puede reprogramarse desde su estado actual.');
        }

        return DB::transaction(function () use ($interview, $actor, $previous, $newScheduledAt, $reason, $data): Interview {
            InterviewReschedule::create([
                'interview_id' => $interview->id,
                'requested_by' => $actor->id,
                'previous_scheduled_at' => $previous,
                'new_scheduled_at' => $newScheduledAt,
                'reason' => $reason,
            ]);

            $interview->forceFill(array_merge([
                'scheduled_at' => $newScheduledAt,
                'state' => InterviewState::Propuesta->value,
            ], array_intersect_key($data, array_flip([
                'duration_minutes',
                'meeting_url',
                'location',
            ]))))->save();

            return $interview->fresh() ?? $interview;
        });
    }

    public function confirm(Interview $interview): Interview
    {
        $state = $interview->state ?? InterviewState::Propuesta;

        if ($state === InterviewState::Confirmada) {
            return $interview;
        }

        if (! InterviewStateMachine::canTransition($state, InterviewState::Confirmada)) {
            throw new RuntimeException('La entrevista no puede confirmarse en este estado.');
        }

        $interview->forceFill(['state' => InterviewState::Confirmada->value])->save();

        $this->notifyParties($interview, new InterviewConfirmedNotification($interview));

        return $interview->fresh() ?? $interview;
    }

    public function cancel(Interview $interview, ?string $reason = null): Interview
    {
        $state = $interview->state ?? InterviewState::Propuesta;

        if (! InterviewStateMachine::canTransition($state, InterviewState::Cancelada)) {
            throw new RuntimeException('La entrevista ya no puede cancelarse.');
        }

        $interview->forceFill([
            'state' => InterviewState::Cancelada->value,
        ])->save();

        if ($reason !== null && $reason !== '') {
            $interview->forceFill([
                'recruiter_feedback' => trim(
                    ($interview->recruiter_feedback ?? '')."\n[cancelado] {$reason}"
                ),
            ])->save();
        }

        $this->notifyParties($interview, new InterviewCancelledNotification($interview, $reason));

        return $interview->fresh() ?? $interview;
    }

    /**
     * Notifica al candidato y a los owners/managers de la empresa.
     */
    private function notifyParties(Interview $interview, mixed $notification): void
    {
        $assignment = $interview->assignment;
        if ($assignment === null) {
            return;
        }

        $recipients = collect();

        $candidateUser = $assignment->candidateProfile?->user;
        if ($candidateUser !== null) {
            $recipients->push($candidateUser);
        }

        $companyUsers = $assignment->vacancy?->company
            ?->members()
            ->with('user')
            ->whereIn('role', ['owner', 'manager'])
            ->get()
            ->pluck('user')
            ->filter() ?? collect();

        $recipients = $recipients->merge($companyUsers)->unique('id')->filter()->values();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, $notification);
    }
}
