<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\InterviewMode;
use App\Enums\InterviewState;
use App\Enums\UserRole;
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

            $alternateRaw = $data['alternate_scheduled_at'] ?? null;
            $alternate = $alternateRaw !== null && $alternateRaw !== ''
                ? Carbon::parse((string) $alternateRaw)
                : null;

            $interview = $assignment->interviews()->create([
                'scheduled_by' => $scheduledBy->id,
                'round' => $round,
                'title' => $data['title'] ?? null,
                'state' => InterviewState::Propuesta->value,
                'mode' => InterviewMode::Online->value, // Solo online en esta fase.
                'scheduled_at' => Carbon::parse((string) $data['scheduled_at']),
                'alternate_scheduled_at' => $alternate,
                'duration_minutes' => (int) ($data['duration_minutes'] ?? 60),
                'timezone' => $data['timezone'] ?? 'America/Mexico_City',
                'meeting_url' => $data['meeting_url'] ?? null,
                'meeting_provider' => $data['meeting_provider'] ?? null,
                'meeting_id' => $data['meeting_id'] ?? null,
                'location' => null,
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

        // Si hay un slot alternativo pendiente, la entrevista no está lista para
        // confirmarse: primero el candidato debe escoger uno via selectSlot().
        if ($interview->alternate_scheduled_at !== null) {
            throw new RuntimeException('El candidato debe escoger uno de los dos horarios antes de confirmar.');
        }

        // Si la empresa propuso la entrevista, el reclutador HUMAE debe agregar
        // el enlace de la reunión antes de que pueda confirmarse. Las entrevistas
        // creadas por el propio reclutador ya manejan su flujo (puede o no traer link).
        if ($this->wasScheduledByCompany($interview)
            && ($interview->meeting_url ?? '') === ''
        ) {
            throw new RuntimeException('El reclutador HUMAE debe agregar el enlace de la reunión antes de confirmar.');
        }

        if (! InterviewStateMachine::canTransition($state, InterviewState::Confirmada)) {
            throw new RuntimeException('La entrevista no puede confirmarse en este estado.');
        }

        $interview->forceFill(['state' => InterviewState::Confirmada->value])->save();

        $this->notifyParties($interview, new InterviewConfirmedNotification($interview));

        return $interview->fresh() ?? $interview;
    }

    /**
     * El candidato (o cualquier parte autorizada) escoge uno de los dos
     * horarios propuestos por la empresa. El slot ganador queda en
     * `scheduled_at` y el alternativo se borra.
     *
     * Después de seleccionar, la entrevista sigue en `Propuesta` esperando
     * que el reclutador HUMAE agregue el `meeting_url`.
     */
    public function selectSlot(Interview $interview, int $slot): Interview
    {
        if ($slot !== 1 && $slot !== 2) {
            throw new RuntimeException('Solo puedes escoger el horario 1 o 2.');
        }

        if ($interview->alternate_scheduled_at === null) {
            throw new RuntimeException('Esta entrevista no tiene dos horarios propuestos.');
        }

        $state = $interview->state ?? InterviewState::Propuesta;
        if ($state !== InterviewState::Propuesta && $state !== InterviewState::Reprogramada) {
            throw new RuntimeException('No puedes escoger horario en el estado actual de la entrevista.');
        }

        if ($slot === 2) {
            $primary = $interview->scheduled_at;
            $alt = $interview->alternate_scheduled_at;
            $interview->forceFill([
                'scheduled_at' => $alt,
                'alternate_scheduled_at' => $primary,
            ])->save();
        }

        // Limpia el slot alternativo en ambos casos: ya hay una decisión.
        $interview->forceFill(['alternate_scheduled_at' => null])->save();

        return $interview->fresh() ?? $interview;
    }

    /**
     * El reclutador HUMAE agrega el enlace de la reunión después de que el
     * candidato escogió el horario. Si la entrevista no tenía link y ya tiene
     * slot definitivo, queda lista para que el candidato la confirme.
     *
     * @param  array{meeting_url: string, meeting_provider?: string|null, meeting_id?: string|null}  $data
     */
    public function addMeetingDetails(Interview $interview, array $data): Interview
    {
        if ($interview->alternate_scheduled_at !== null) {
            throw new RuntimeException('El candidato aún no escoge horario; no se puede fijar el enlace.');
        }

        $interview->forceFill([
            'meeting_url' => $data['meeting_url'],
            'meeting_provider' => $data['meeting_provider'] ?? $interview->meeting_provider,
            'meeting_id' => $data['meeting_id'] ?? $interview->meeting_id,
        ])->save();

        return $interview->fresh() ?? $interview;
    }

    /**
     * Marca la entrevista como realizada y persiste feedback + recomendación.
     *
     * @param  array{recruiter_feedback: string, recommendation: string, rating?: int|null}  $data
     */
    public function complete(Interview $interview, array $data): Interview
    {
        $state = $interview->state ?? InterviewState::Propuesta;

        if (! InterviewStateMachine::canTransition($state, InterviewState::Realizada)) {
            throw new RuntimeException('La entrevista no puede marcarse como realizada en este estado.');
        }

        $interview->forceFill([
            'state' => InterviewState::Realizada->value,
            'recruiter_feedback' => $data['recruiter_feedback'],
            'recommendation' => $data['recommendation'],
            'rating' => $data['rating'] ?? null,
            'ended_at' => now(),
        ])->save();

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
     * Una entrevista fue propuesta por la empresa si quien la agendó tiene
     * rol company_user (no recruiter/admin).
     */
    private function wasScheduledByCompany(Interview $interview): bool
    {
        $scheduler = $interview->scheduler;
        if ($scheduler === null) {
            return false;
        }
        if ($scheduler->hasAnyRole([UserRole::Recruiter->value, UserRole::Admin->value])) {
            return false;
        }

        return $scheduler->hasRole(UserRole::CompanyUser->value);
    }

    /**
     * Notifica al candidato, owners/managers de la empresa, y al recruiter
     * asignado a la vacante (si existe).
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

        $recipients = $recipients->merge($companyUsers);

        $assignedRecruiter = $assignment->vacancy?->recruiter;
        if ($assignedRecruiter !== null) {
            $recipients->push($assignedRecruiter);
        }

        $recipients = $recipients->unique('id')->filter()->values();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, $notification);
    }
}
