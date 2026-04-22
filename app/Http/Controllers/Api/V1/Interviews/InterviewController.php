<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Interviews;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Interviews\CompleteInterviewRequest;
use App\Http\Requests\Interviews\ScheduleInterviewRequest;
use App\Http\Requests\Interviews\UpdateInterviewRequest;
use App\Http\Resources\V1\Interviews\InterviewResource;
use App\Models\Interview;
use App\Models\User;
use App\Models\VacancyAssignment;
use App\Services\InterviewService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response as HttpStatus;
use Throwable;

class InterviewController extends Controller
{
    public function __construct(
        private readonly InterviewService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $query = Interview::query()->with('assignment.candidateProfile', 'assignment.vacancy');

        $this->scopeByRole($query, $user);

        if ($request->filled('assignment_id')) {
            $query->where('vacancy_assignment_id', (int) $request->input('assignment_id'));
        }

        if ($request->filled('state')) {
            $query->where('state', (string) $request->input('state'));
        }

        if ($request->filled('from')) {
            $query->where('scheduled_at', '>=', Carbon::parse((string) $request->input('from')));
        }
        if ($request->filled('to')) {
            $query->where('scheduled_at', '<=', Carbon::parse((string) $request->input('to')));
        }

        $interviews = $query->orderBy('scheduled_at')->paginate(30);

        return $this->success(
            message: 'Entrevistas.',
            data: InterviewResource::collection($interviews),
            meta: [
                'pagination' => [
                    'current_page' => $interviews->currentPage(),
                    'per_page' => $interviews->perPage(),
                    'total' => $interviews->total(),
                    'last_page' => $interviews->lastPage(),
                ],
            ],
        );
    }

    public function store(ScheduleInterviewRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $isStaff = $user->hasAnyRole([UserRole::Recruiter->value, UserRole::Admin->value]);
        $isCompany = $user->hasRole(UserRole::CompanyUser->value);

        if (! $isStaff && ! $isCompany) {
            return $this->error(
                'No tienes permiso para proponer entrevistas.',
                status: HttpStatus::HTTP_FORBIDDEN,
            );
        }

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $assignment = VacancyAssignment::with('vacancy')
            ->findOrFail((int) $data['vacancy_assignment_id']);

        // Company_user sólo puede proponer entrevistas para asignaciones de su propia empresa.
        if (! $isStaff && $isCompany) {
            $companyId = $assignment->vacancy?->company_id;
            $belongs = $companyId !== null
                && $user->companyMemberships()->where('company_id', $companyId)->exists();
            if (! $belongs) {
                return $this->error(
                    'No puedes proponer entrevistas para esta asignación.',
                    status: HttpStatus::HTTP_FORBIDDEN,
                );
            }
        }

        try {
            $interview = $this->service->schedule($assignment, $user, $data);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), status: HttpStatus::HTTP_CONFLICT);
        }

        $interview->load('assignment.candidateProfile', 'assignment.vacancy');

        return $this->success(
            message: 'Entrevista propuesta.',
            data: InterviewResource::make($interview),
            status: HttpStatus::HTTP_CREATED,
        );
    }

    public function show(Request $request, Interview $interview): JsonResponse
    {
        $this->authorizeAccess($request, $interview);
        $interview->load('assignment.candidateProfile', 'assignment.vacancy');

        return $this->success(
            message: 'Entrevista.',
            data: InterviewResource::make($interview),
        );
    }

    public function update(UpdateInterviewRequest $request, Interview $interview): JsonResponse
    {
        $this->authorizeRecruiter($request);

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        // Si viene scheduled_at, trátalo como reprogramación
        if (isset($data['scheduled_at'])) {
            /** @var User $user */
            $user = $request->user();
            try {
                $this->service->reschedule(
                    $interview,
                    $user,
                    Carbon::parse((string) $data['scheduled_at']),
                    $data['reason'] ?? null,
                    $data,
                );
            } catch (Throwable $e) {
                return $this->error($e->getMessage(), status: HttpStatus::HTTP_CONFLICT);
            }
            unset($data['scheduled_at'], $data['reason']);
            $interview = $interview->fresh() ?? $interview;
        }

        if ($data !== []) {
            $interview->fill($data)->save();
        }

        $interview->load('assignment.candidateProfile', 'assignment.vacancy');

        return $this->success(
            message: 'Entrevista actualizada.',
            data: InterviewResource::make($interview),
        );
    }

    public function confirm(Request $request, Interview $interview): JsonResponse
    {
        $this->authorizeAccess($request, $interview);

        try {
            $this->service->confirm($interview);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), status: HttpStatus::HTTP_CONFLICT);
        }

        return $this->success(
            message: 'Entrevista confirmada.',
            data: InterviewResource::make($interview->fresh(['assignment.candidateProfile', 'assignment.vacancy'])),
        );
    }

    public function complete(CompleteInterviewRequest $request, Interview $interview): JsonResponse
    {
        $this->authorizeRecruiter($request);

        /** @var array{recruiter_feedback: string, recommendation: string, rating?: int|null} $data */
        $data = $request->validated();

        try {
            $this->service->complete($interview, $data);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), status: HttpStatus::HTTP_CONFLICT);
        }

        return $this->success(
            message: 'Entrevista marcada como realizada.',
            data: InterviewResource::make($interview->fresh(['assignment.candidateProfile', 'assignment.vacancy'])),
        );
    }

    public function cancel(Request $request, Interview $interview): JsonResponse
    {
        $this->authorizeAccess($request, $interview);

        $validated = $request->validate([
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        try {
            $this->service->cancel($interview, $validated['reason'] ?? null);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), status: HttpStatus::HTTP_CONFLICT);
        }

        return $this->success(
            message: 'Entrevista cancelada.',
            data: InterviewResource::make($interview->fresh(['assignment.candidateProfile', 'assignment.vacancy'])),
        );
    }

    /**
     * @param  Builder<Interview>  $query
     */
    private function scopeByRole($query, User $user): void
    {
        if ($user->hasAnyRole([UserRole::Recruiter->value, UserRole::Admin->value])) {
            return;
        }

        if ($user->hasRole(UserRole::Candidate->value)) {
            $query->whereHas('assignment.candidateProfile', function ($q) use ($user): void {
                $q->where('user_id', $user->id);
            });

            return;
        }

        if ($user->hasRole(UserRole::CompanyUser->value)) {
            $companyIds = $user->companyMemberships()->pluck('company_id');
            $query->whereHas('assignment.vacancy', function ($q) use ($companyIds): void {
                $q->whereIn('company_id', $companyIds);
            });

            return;
        }

        // Sin rol reconocido: sin resultados
        $query->whereRaw('1 = 0');
    }

    private function authorizeAccess(Request $request, Interview $interview): void
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->hasAnyRole([UserRole::Recruiter->value, UserRole::Admin->value])) {
            return;
        }

        $assignment = $interview->assignment;
        if ($assignment === null) {
            abort(HttpStatus::HTTP_NOT_FOUND);
        }

        // Candidate: solo sus entrevistas
        if ($user->hasRole(UserRole::Candidate->value)) {
            if ($assignment->candidateProfile?->user_id === $user->id) {
                return;
            }
        }

        // Company_user: solo si pertenece a la empresa de la vacante
        if ($user->hasRole(UserRole::CompanyUser->value)) {
            $companyId = $assignment->vacancy?->company_id;
            if ($companyId !== null
                && $user->companyMemberships()->where('company_id', $companyId)->exists()) {
                return;
            }
        }

        abort(HttpStatus::HTTP_FORBIDDEN);
    }

    private function authorizeRecruiter(Request $request): void
    {
        /** @var User $user */
        $user = $request->user();
        if (! $user->hasAnyRole([UserRole::Recruiter->value, UserRole::Admin->value])) {
            abort(HttpStatus::HTTP_FORBIDDEN);
        }
    }
}
