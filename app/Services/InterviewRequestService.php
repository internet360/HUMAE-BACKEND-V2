<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AssignmentStage;
use App\Enums\CompanyMemberRole;
use App\Enums\InterviewRequestCandidateState;
use App\Enums\InterviewRequestState;
use App\Enums\MembershipStatus;
use App\Enums\UserRole;
use App\Enums\VacancyState;
use App\Models\CandidateProfile;
use App\Models\Company;
use App\Models\InterviewRequest;
use App\Models\InterviewRequestCandidate;
use App\Models\User;
use App\Models\Vacancy;
use App\Notifications\InterviewRequestCandidateVetoedNotification;
use App\Notifications\InterviewRequestSubmittedNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use RuntimeException;

/**
 * Alta de una solicitud de entrevistas del empleador.
 *
 * Los cuatro pasos del flujo —elegir perfiles, crear la vacante breve, proponer
 * dos horarios y enviar— terminan en una sola escritura. No es capricho: si la
 * vacante se creara por separado y el envío fallara, quedaría una vacante
 * huérfana en `solicitada` que nadie pidió y que HUMAE ve como trabajo por
 * atender. O todo, o nada.
 */
class InterviewRequestService
{
    public function __construct(
        private readonly VacancyIdentifierService $identifiers,
        private readonly PipelineService $pipeline,
    ) {}

    /**
     * @param  array<string, mixed>  $vacancyData  campos breves de la vacante
     * @param  list<string>  $candidateReferences  referencias públicas elegidas
     * @param  list<string>  $slots  los dos horarios propuestos
     */
    public function submit(
        User $requester,
        Company $company,
        array $vacancyData,
        array $candidateReferences,
        array $slots,
        ?string $timezone = null,
        ?string $note = null,
    ): InterviewRequest {
        // El Form Request ya exige `size:2`, y aun así se vuelve a comprobar
        // aquí. Un servicio que confía en que su único llamador de hoy validó
        // bien es un servicio que se rompe con el segundo llamador — y «dos
        // horarios» es regla de negocio, no de formulario.
        if (count($slots) !== 2) {
            throw new RuntimeException('Se requieren exactamente dos horarios propuestos.');
        }

        // Se resuelven ANTES de abrir la transacción: si una referencia no
        // existe —o apunta a alguien que ya no está disponible— la solicitud no
        // debe llegar a crear nada.
        $candidates = $this->resolveCandidates($candidateReferences);

        return DB::transaction(function () use (
            $requester,
            $company,
            $vacancyData,
            $candidates,
            $slots,
            $timezone,
            $note,
        ): InterviewRequest {
            $vacancy = Vacancy::create([
                ...$vacancyData,
                'company_id' => $company->id,
                'created_by' => $requester->id,
                'state' => VacancyState::Solicitada->value,
                'published_at' => null,
                'slug' => $this->identifiers->uniqueSlug((string) $vacancyData['title']),
                'code' => $this->identifiers->nextCode(),
            ]);

            $request = InterviewRequest::create([
                'company_id' => $company->id,
                'vacancy_id' => $vacancy->id,
                'requested_by_user_id' => $requester->id,
                'state' => InterviewRequestState::Pendiente->value,
                'proposed_slot_1_at' => Carbon::parse($slots[0]),
                'proposed_slot_2_at' => Carbon::parse($slots[1]),
                'timezone' => $timezone ?? 'America/Mexico_City',
                'note' => $note,
                'submitted_at' => now(),
            ]);

            foreach ($candidates as $candidate) {
                $request->candidates()->create([
                    'candidate_profile_id' => $candidate->id,
                    'state' => InterviewRequestCandidateState::Pendiente->value,
                ]);
            }

            $this->notifyStaff($request);

            return $request->load(['vacancy', 'candidates.candidateProfile']);
        });
    }

    /**
     * HUMAE acepta un perfil señalado: nace su asignación y queda presentado.
     *
     * Nace en `sourced` y avanza a `presented` en el mismo acto, sin pasar por
     * la lista interna. `sourced` es la preselección que el cliente no ve, y
     * este candidato ya lo eligió el cliente: dejarlo ahí sería esconderle a
     * alguien que él mismo señaló.
     */
    public function accept(InterviewRequestCandidate $item, User $recruiter): InterviewRequestCandidate
    {
        $this->guardResolvable($item);

        $request = $item->interviewRequest;
        $vacancy = $request?->vacancy;

        if ($request === null || $vacancy === null) {
            throw new RuntimeException('La solicitud no está vinculada a una vacante.');
        }

        $candidate = $item->candidateProfile;
        if ($candidate === null) {
            throw new RuntimeException('El perfil señalado ya no existe.');
        }

        return DB::transaction(function () use ($item, $request, $vacancy, $candidate, $recruiter): InterviewRequestCandidate {
            $assignment = $this->pipeline->assign($vacancy, $candidate, $recruiter);
            $assignment = $this->pipeline->changeStage($assignment, AssignmentStage::Presented);

            $item->forceFill([
                'state' => InterviewRequestCandidateState::Aceptado->value,
                'vacancy_assignment_id' => $assignment->id,
                'resolved_by_user_id' => $recruiter->id,
                'resolved_at' => now(),
            ])->save();

            $this->takeIfUnclaimed($request, $recruiter);
            $this->refreshRequestState($request);

            return $item->fresh(['candidateProfile', 'vacancyAssignment']) ?? $item;
        });
    }

    /**
     * HUMAE no presenta a esta persona. Se cae ella sola, con motivo.
     */
    public function reject(
        InterviewRequestCandidate $item,
        User $recruiter,
        string $reason,
    ): InterviewRequestCandidate {
        $this->guardResolvable($item);

        $request = $item->interviewRequest;
        if ($request === null) {
            throw new RuntimeException('La solicitud ya no existe.');
        }

        return DB::transaction(function () use ($item, $request, $recruiter, $reason): InterviewRequestCandidate {
            $item->forceFill([
                'state' => InterviewRequestCandidateState::Vetado->value,
                'rejection_reason' => $reason,
                'resolved_by_user_id' => $recruiter->id,
                'resolved_at' => now(),
            ])->save();

            $this->takeIfUnclaimed($request, $recruiter);
            $this->refreshRequestState($request);

            $this->notifyCompanyOfVeto($item->fresh() ?? $item, $request);

            return $item->fresh(['candidateProfile']) ?? $item;
        });
    }

    private function guardResolvable(InterviewRequestCandidate $item): void
    {
        if ($item->state?->isResolved() === true) {
            throw new RuntimeException('Ese perfil ya fue resuelto en esta solicitud.');
        }

        $request = $item->interviewRequest;

        if ($request?->state?->isOpen() !== true) {
            throw new RuntimeException('La solicitud ya no admite cambios.');
        }
    }

    private function takeIfUnclaimed(InterviewRequest $request, User $recruiter): void
    {
        if ($request->state === InterviewRequestState::Pendiente) {
            $request->forceFill([
                'state' => InterviewRequestState::EnGestion->value,
                'assigned_recruiter_id' => $request->assigned_recruiter_id ?? $recruiter->id,
            ])->save();
        }
    }

    /**
     * Cierra la solicitud cuando ya no queda nadie por resolver, y mueve la
     * vacante en consecuencia.
     *
     * El caso que importa es el de todos vetados: sin esta rama la vacante se
     * queda en `solicitada` esperando candidatos que ya no van a llegar por ahí.
     * Pasa a `en_busqueda` y HUMAE la trabaja como cualquier otra.
     */
    private function refreshRequestState(InterviewRequest $request): void
    {
        $pending = $request->candidates()
            ->where('state', InterviewRequestCandidateState::Pendiente->value)
            ->exists();

        if ($pending) {
            return;
        }

        $request->forceFill([
            'state' => InterviewRequestState::Atendida->value,
            'resolved_at' => now(),
        ])->save();

        $accepted = $request->candidates()
            ->where('state', InterviewRequestCandidateState::Aceptado->value)
            ->exists();

        $vacancy = $request->vacancy;

        if (! $accepted && $vacancy?->state === VacancyState::Solicitada) {
            $vacancy->forceFill(['state' => VacancyState::EnBusqueda->value])->save();
        }
    }

    private function notifyCompanyOfVeto(InterviewRequestCandidate $item, InterviewRequest $request): void
    {
        $recipients = $request->company
            ?->members()
            ->with('user')
            ->whereIn('role', [CompanyMemberRole::Owner->value, CompanyMemberRole::Manager->value])
            ->get()
            ->pluck('user')
            ->filter()
            ->values();

        if ($recipients === null || $recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new InterviewRequestCandidateVetoedNotification($item));
    }

    /**
     * Traduce las referencias públicas a perfiles, exigiendo que sigan siendo
     * elegibles.
     *
     * El mismo filtro que sirve la vista anónima —membresía vigente y estado
     * visible— se vuelve a aplicar aquí. Entre que la empresa cargó la lista y
     * apretó enviar pueden pasar días: sin esta comprobación, una referencia
     * copiada de una pestaña vieja arrastra a alguien que ya venció su
     * membresía o que HUMAE dio de baja.
     *
     * @param  list<string>  $references
     * @return list<CandidateProfile>
     */
    private function resolveCandidates(array $references): array
    {
        $unique = array_values(array_unique($references));

        /** @var list<CandidateProfile> $found */
        $found = CandidateProfile::query()
            ->whereIn('public_reference', $unique)
            ->whereIn('state', DirectorySearchService::companyVisibleStates())
            ->whereHas('user.memberships', function ($m): void {
                $m->where('status', MembershipStatus::Active->value)
                    ->where('expires_at', '>', now());
            })
            ->get()
            ->all();

        if (count($found) !== count($unique)) {
            // Sin decir cuál ni por qué: distinguir «no existe» de «ya no está
            // disponible» le daría a la empresa una sonda para leer el estado
            // interno de personas que sólo conoce por una referencia opaca.
            throw new RuntimeException(
                'Alguno de los perfiles seleccionados ya no está disponible. Vuelve a cargar la lista.',
            );
        }

        return $found;
    }

    private function notifyStaff(InterviewRequest $request): void
    {
        $recipients = User::query()
            ->whereHas('roles', function ($q): void {
                $q->whereIn('name', [UserRole::Recruiter->value, UserRole::Admin->value]);
            })
            ->get();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new InterviewRequestSubmittedNotification($request));
    }
}
