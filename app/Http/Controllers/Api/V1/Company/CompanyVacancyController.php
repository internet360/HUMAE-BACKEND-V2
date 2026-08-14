<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Company;

use App\Enums\AssignmentStage;
use App\Enums\UserRole;
use App\Enums\VacancyState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Companies\VacancyRequest;
use App\Http\Resources\V1\Companies\VacancyResource;
use App\Http\Resources\V1\Pipeline\CompanyAssignmentResource;
use App\Models\User;
use App\Models\Vacancy;
use App\Models\VacancyAssignment;
use App\Services\VacancyIdentifierService;
use App\Services\VacancyStateMachine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response as HttpStatus;

/**
 * Endpoints para usuarios tipo `company_user`. Solo operan sobre las vacantes
 * de las empresas a las que están vinculados vía company_members.
 */
class CompanyVacancyController extends Controller
{
    public function __construct(
        private readonly VacancyIdentifierService $identifiers,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->hasAnyRole([UserRole::CompanyUser->value, UserRole::Admin->value])) {
            return $this->error('No tienes acceso a este recurso.', status: HttpStatus::HTTP_FORBIDDEN);
        }

        $companyIds = $user->companyMemberships()->pluck('company_id');

        $vacancies = Vacancy::query()
            ->whereIn('company_id', $companyIds)
            ->with(['company', 'signedAddendum'])
            ->orderByDesc('created_at')
            ->paginate(20);

        return $this->success(
            message: 'Vacantes de tu empresa.',
            data: VacancyResource::collection($vacancies),
            meta: [
                'pagination' => [
                    'current_page' => $vacancies->currentPage(),
                    'per_page' => $vacancies->perPage(),
                    'total' => $vacancies->total(),
                    'last_page' => $vacancies->lastPage(),
                ],
            ],
        );
    }

    /**
     * A company files a vacancy request. It always lands in `borrador` and
     * HUMAE reviews it: the company neither publishes on creation nor attaches
     * candidates (ARCHITECTURE.md §6, "Asignar candidatos a vacante — Empresa
     * cliente: ❌"). Assigning is HUMAE's curation step, done from
     * POST /vacancies/{vacancy}/assignments.
     */
    public function store(VacancyRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->hasRole(UserRole::CompanyUser->value) && ! $user->hasRole(UserRole::Admin->value)) {
            return $this->error('No tienes acceso a este recurso.', status: HttpStatus::HTTP_FORBIDDEN);
        }

        // `company_id` is guarded in VacancyRequest against the caller's own
        // memberships, and `assigned_recruiter_id` — along with the rest of
        // HUMAE's commercial terms — is refused there too. The controller no
        // longer re-states either rule.
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $vacancy = Vacancy::create([
            ...$data,
            'created_by' => $user->id,
            'state' => VacancyState::Borrador->value,
            'published_at' => null,
            'slug' => $this->identifiers->uniqueSlug((string) $data['title']),
            'code' => $this->identifiers->nextCode(),
        ]);

        $vacancy->load(['company', 'signedAddendum']);

        return $this->success(
            message: 'Vacante creada en borrador. HUMAE la revisará.',
            data: VacancyResource::make($vacancy),
            status: HttpStatus::HTTP_CREATED,
        );
    }

    public function show(Request $request, Vacancy $vacancy): JsonResponse
    {
        $this->authorize('view', $vacancy);

        $vacancy->load(['company', 'signedAddendum']);

        return $this->success(
            message: 'Vacante.',
            data: VacancyResource::make($vacancy),
        );
    }

    public function update(VacancyRequest $request, Vacancy $vacancy): JsonResponse
    {
        $this->authorize('update', $vacancy);

        if ($vacancy->state === VacancyState::Cubierta || $vacancy->state === VacancyState::Cancelada) {
            return $this->error(
                'No puedes editar una vacante en estado terminal.',
                status: HttpStatus::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $vacancy->update($request->validated());
        $vacancy->load(['company', 'signedAddendum']);

        return $this->success(
            message: 'Vacante actualizada.',
            data: VacancyResource::make($vacancy->fresh('company')),
        );
    }

    /**
     * Move one of the company's own vacancies through its state machine.
     *
     * The hand-rolled whitelist this replaced was inverted against §6 (F-10):
     * it let the company activate its own vacancy — "Aprobar / activar vacante:
     * Empresa ❌" — and blocked it from proposing `cubierta` — "Marcar vacante
     * como cubierta: Empresa ✅ (propone)". Deriving the ability from the target
     * state puts the rule in the policy, where §6 can be read off it.
     */
    public function transition(Request $request, Vacancy $vacancy): JsonResponse
    {
        $states = array_map(fn (VacancyState $s) => $s->value, VacancyState::cases());

        $validated = $request->validate([
            'to' => ['required', Rule::in($states)],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $from = $vacancy->state ?? VacancyState::Borrador;
        $to = VacancyState::from($validated['to']);

        $this->authorize(VacancyStateMachine::abilityFor($to), $vacancy);

        if (! VacancyStateMachine::canTransition($from, $to)) {
            return $this->error(
                message: "Transición inválida: {$from->value} → {$to->value}.",
                errors: ['to' => [
                    'allowed' => VacancyStateMachine::allowedValuesFrom($from),
                ]],
                status: HttpStatus::HTTP_CONFLICT,
            );
        }

        $payload = ['state' => $to->value];

        if ($to === VacancyState::Activa && $vacancy->published_at === null) {
            $payload['published_at'] = now();
        }
        if ($to === VacancyState::Cubierta) {
            $payload['filled_at'] = now();
        }
        if ($to === VacancyState::Cancelada) {
            $payload['cancelled_at'] = now();
            $payload['cancel_reason'] = $validated['reason'] ?? null;
        }

        $vacancy->update($payload);
        $vacancy->load(['company', 'signedAddendum']);

        return $this->success(
            message: "Estado actualizado a {$to->value}.",
            data: VacancyResource::make($vacancy->fresh('company')),
        );
    }

    public function assignments(Request $request, Vacancy $vacancy): JsonResponse
    {
        $this->authorize('view', $vacancy);

        // La empresa solo ve lo que HUMAE decidió presentarle. Este whereIn es
        // el límite de confidencialidad del pipeline: sin él, el cliente lee la
        // lista interna del reclutador (`sourced`) y sus descartes (`rejected`).
        $visibleStages = AssignmentStage::visibleToCompanyValues();

        $query = VacancyAssignment::query()
            ->where('vacancy_id', $vacancy->id)
            ->whereIn('stage', $visibleStages)
            ->with(['candidateProfile.user', 'candidateProfile.skills']);

        // El filtro solo puede estrechar el conjunto visible, nunca ampliarlo:
        // pedir `?stage=sourced` devuelve vacío, no las filas ocultas.
        if ($request->filled('stage')) {
            $requested = (string) $request->input('stage');
            $query->whereIn(
                'stage',
                \in_array($requested, $visibleStages, true) ? [$requested] : [],
            );
        }

        $assignments = $query
            ->orderByDesc('created_at')
            ->get();

        return $this->success(
            message: 'Candidatos en el flujo de selección.',
            data: CompanyAssignmentResource::collection($assignments),
        );
    }
}
