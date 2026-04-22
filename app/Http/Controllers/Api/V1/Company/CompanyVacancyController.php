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
use App\Services\VacancyStateMachine;
use Cocur\Slugify\Slugify;
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
            ->with('company')
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

    public function store(VacancyRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->hasRole(UserRole::CompanyUser->value) && ! $user->hasRole(UserRole::Admin->value)) {
            return $this->error('No tienes acceso a este recurso.', status: HttpStatus::HTTP_FORBIDDEN);
        }

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $isMember = $user->companyMemberships()
            ->where('company_id', (int) $data['company_id'])
            ->exists();

        if (! $isMember && ! $user->hasRole(UserRole::Admin->value)) {
            return $this->error(
                'No puedes crear vacantes para esta empresa.',
                status: HttpStatus::HTTP_FORBIDDEN,
            );
        }

        $vacancy = Vacancy::create([
            ...$data,
            'created_by' => $user->id,
            'state' => VacancyState::Borrador->value,
            'slug' => $this->uniqueSlug((string) $data['title']),
            'code' => $this->nextVacancyCode(),
        ]);
        $vacancy->load('company');

        return $this->success(
            message: 'Vacante creada en borrador. HUMAE la revisará.',
            data: VacancyResource::make($vacancy),
            status: HttpStatus::HTTP_CREATED,
        );
    }

    public function show(Request $request, Vacancy $vacancy): JsonResponse
    {
        $this->authorize('view', $vacancy);

        $vacancy->load('company');

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
        $vacancy->load('company');

        return $this->success(
            message: 'Vacante actualizada.',
            data: VacancyResource::make($vacancy->fresh('company')),
        );
    }

    public function transition(Request $request, Vacancy $vacancy): JsonResponse
    {
        $this->authorize('update', $vacancy);

        $states = array_map(fn (VacancyState $s) => $s->value, VacancyState::cases());

        $validated = $request->validate([
            'to' => ['required', Rule::in($states)],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $from = $vacancy->state ?? VacancyState::Borrador;
        $to = VacancyState::from($validated['to']);

        // Company_user sólo puede disparar: publicar (borrador→activa) y cancelar.
        $allowedForCompany = [VacancyState::Activa, VacancyState::Cancelada];
        if (! in_array($to, $allowedForCompany, true)) {
            return $this->error(
                'Sólo puedes publicar o cancelar vacantes desde tu empresa.',
                status: HttpStatus::HTTP_FORBIDDEN,
            );
        }

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
        if ($to === VacancyState::Cancelada) {
            $payload['cancelled_at'] = now();
            $payload['cancel_reason'] = $validated['reason'] ?? null;
        }

        $vacancy->update($payload);
        $vacancy->load('company');

        return $this->success(
            message: "Estado actualizado a {$to->value}.",
            data: VacancyResource::make($vacancy->fresh('company')),
        );
    }

    public function assignments(Request $request, Vacancy $vacancy): JsonResponse
    {
        $this->authorize('view', $vacancy);

        $visibleStages = [
            AssignmentStage::Presented->value,
            AssignmentStage::Interviewing->value,
            AssignmentStage::Finalist->value,
            AssignmentStage::Hired->value,
        ];

        $assignments = VacancyAssignment::query()
            ->where('vacancy_id', $vacancy->id)
            ->whereIn('stage', $visibleStages)
            ->with(['candidateProfile.user', 'candidateProfile.skills'])
            ->orderByDesc('presented_at')
            ->get();

        return $this->success(
            message: 'Candidatos presentados.',
            data: CompanyAssignmentResource::collection($assignments),
        );
    }

    private function uniqueSlug(string $title): string
    {
        $slugify = new Slugify;
        $base = $slugify->slugify($title) ?: 'vacante';
        $slug = $base;
        $i = 1;
        while (Vacancy::where('slug', $slug)->exists()) {
            $i++;
            $slug = $base.'-'.$i;
        }

        return $slug;
    }

    private function nextVacancyCode(): string
    {
        $year = (int) now()->format('Y');
        $prefix = "HUM-{$year}-";

        $last = Vacancy::where('code', 'like', $prefix.'%')
            ->orderByDesc('code')
            ->value('code');

        $next = 1;
        if ($last !== null) {
            $segment = substr((string) $last, strlen($prefix));
            $next = ((int) $segment) + 1;
        }

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
