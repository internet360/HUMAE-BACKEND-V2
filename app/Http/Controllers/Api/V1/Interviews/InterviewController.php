<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Interviews;

use App\Enums\AssignmentStage;
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

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $assignment = VacancyAssignment::with('vacancy.company')
            ->findOrFail((int) $data['vacancy_assignment_id']);

        // VacancyAssignmentPolicy checks the stage, not just the tenancy: a
        // company may only propose interviews for candidates HUMAE presented.
        $this->authorize('scheduleInterview', $assignment);

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
        $this->authorizeReschedule($request, $interview);

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

    public function selectSlot(Request $request, Interview $interview): JsonResponse
    {
        $this->authorizeSlotSelection($request, $interview);

        $validated = $request->validate([
            'slot' => ['required', 'integer', 'in:1,2'],
        ]);

        try {
            $fresh = $this->service->selectSlot($interview, (int) $validated['slot']);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), status: HttpStatus::HTTP_CONFLICT);
        }

        $fresh->load('assignment.candidateProfile', 'assignment.vacancy');

        return $this->success(
            message: 'Horario seleccionado. El reclutador HUMAE agregará el enlace de la reunión.',
            data: InterviewResource::make($fresh),
        );
    }

    public function addMeetingDetails(Request $request, Interview $interview): JsonResponse
    {
        $this->authorizeRecruiter($request);

        $validated = $request->validate([
            'meeting_url' => ['required', 'url', 'max:600'],
            'meeting_provider' => ['sometimes', 'nullable', 'string', 'max:40'],
            'meeting_id' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        try {
            $fresh = $this->service->addMeetingDetails($interview, $validated);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), status: HttpStatus::HTTP_CONFLICT);
        }

        $fresh->load('assignment.candidateProfile', 'assignment.vacancy');

        return $this->success(
            message: 'Enlace de reunión agregado.',
            data: InterviewResource::make($fresh),
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
            // Tenancy AND confidentiality: the company reads the interviews of
            // her own vacancies, and only for candidates HUMAE presented to her.
            // Mirrors InterviewPolicy::view for the collection.
            $query->whereHas('assignment', function ($q) use ($companyIds): void {
                $q->whereIn('stage', AssignmentStage::visibleToCompanyValues())
                    ->whereHas('vacancy', function ($v) use ($companyIds): void {
                        $v->whereIn('company_id', $companyIds);
                    });
            });

            return;
        }

        // Sin rol reconocido: sin resultados
        $query->whereRaw('1 = 0');
    }

    private function authorizeAccess(Request $request, Interview $interview): void
    {
        $this->authorize('view', $interview);
    }

    private function authorizeRecruiter(Request $request): void
    {
        /** @var User $user */
        $user = $request->user();
        if (! $user->hasAnyRole([UserRole::Recruiter->value, UserRole::Admin->value])) {
            abort(HttpStatus::HTTP_FORBIDDEN);
        }
    }

    /**
     * Quién puede escoger el slot de la entrevista:
     * - El candidato dueño de la asignación (caso típico).
     * - Recruiter / admin (por soporte / corrección).
     * - Company owner/manager, sólo sobre un candidato ya presentado.
     */
    private function authorizeSlotSelection(Request $request, Interview $interview): void
    {
        $this->authorize('selectSlot', $interview);
    }

    private function authorizeReschedule(Request $request, Interview $interview): void
    {
        $this->authorize('reschedule', $interview);
    }
}
