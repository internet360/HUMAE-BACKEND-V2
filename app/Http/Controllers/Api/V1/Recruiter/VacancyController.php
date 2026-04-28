<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Recruiter;

use App\Enums\UserRole;
use App\Enums\VacancyState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Companies\VacancyRequest;
use App\Http\Resources\V1\Companies\VacancyResource;
use App\Models\User;
use App\Models\Vacancy;
use App\Services\VacancyStateMachine;
use Cocur\Slugify\Slugify;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response as HttpStatus;

class VacancyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Vacancy::class);

        /** @var User $user */
        $user = $request->user();

        $query = Vacancy::query()->with('company');

        if ($user->hasRole(UserRole::CompanyUser->value) && ! $user->hasRole(UserRole::Admin->value)) {
            $companyIds = $user->companyMemberships()->pluck('company_id');
            $query->whereIn('company_id', $companyIds);
        }

        if ($request->filled('company_id')) {
            $query->where('company_id', (int) $request->input('company_id'));
        }

        if ($request->filled('state')) {
            $query->where('state', (string) $request->input('state'));
        }

        if ($request->filled('excluding_assigned_candidate_id')) {
            $candidateId = (int) $request->input('excluding_assigned_candidate_id');
            $query->whereDoesntHave('assignments', function ($q) use ($candidateId) {
                $q->where('candidate_profile_id', $candidateId);
            });
        }

        $vacancies = $query->orderByDesc('created_at')->paginate(20);

        return $this->success(
            message: 'Vacantes.',
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
        $this->authorize('create', Vacancy::class);

        /** @var User $user */
        $user = $request->user();

        /** @var array<string, mixed> $data */
        $data = $request->validated();
        $data['created_by'] = $user->id;
        $data['state'] = VacancyState::Borrador->value;
        $data['slug'] = $this->uniqueSlug((string) $data['title']);
        $data['code'] = $this->nextVacancyCode();

        $vacancy = Vacancy::create($data);
        $vacancy->load('company');

        return $this->success(
            message: 'Vacante creada en borrador.',
            data: VacancyResource::make($vacancy),
            status: HttpStatus::HTTP_CREATED,
        );
    }

    public function show(Vacancy $vacancy): JsonResponse
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

        $vacancy->update($request->validated());
        $vacancy->load('company');

        return $this->success(
            message: 'Vacante actualizada.',
            data: VacancyResource::make($vacancy->fresh('company')),
        );
    }

    public function destroy(Vacancy $vacancy): JsonResponse
    {
        $this->authorize('delete', $vacancy);

        $vacancy->delete();

        return $this->success(message: 'Vacante archivada.', status: HttpStatus::HTTP_NO_CONTENT);
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
        $vacancy->load('company');

        return $this->success(
            message: "Estado actualizado a {$to->value}.",
            data: VacancyResource::make($vacancy->fresh('company')),
        );
    }

    private function uniqueSlug(string $title): string
    {
        $slugify = new Slugify;
        $base = $slugify->slugify($title);
        if ($base === '') {
            $base = 'vacante';
        }
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
