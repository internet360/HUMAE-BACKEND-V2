<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Recruiter;

use App\Enums\InterviewRequestState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Recruiter\RejectInterviewRequestCandidateRequest;
use App\Http\Resources\V1\Pipeline\StaffInterviewRequestResource;
use App\Models\InterviewRequest;
use App\Models\InterviewRequestCandidate;
use App\Models\User;
use App\Services\InterviewRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as HttpStatus;

/**
 * Bandeja de solicitudes de entrevistas, lado HUMAE.
 *
 * Aquí se resuelve la curación: a quién de los que el cliente señaló se le
 * presenta y a quién no. Aceptar crea la asignación en el pipeline; vetar la
 * niega con motivo. Ninguna de las dos la puede tocar la empresa.
 */
class InterviewRequestController extends Controller
{
    public function __construct(
        private readonly InterviewRequestService $requests,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', InterviewRequest::class);

        $states = array_map(
            fn (InterviewRequestState $s) => $s->value,
            InterviewRequestState::cases(),
        );

        $validated = $request->validate([
            'state' => ['sometimes', Rule::in($states)],
            'company_id' => ['sometimes', 'integer', 'exists:companies,id'],
        ]);

        // `acrossCompanies()` es explícito porque HUMAE no es un tenant: opera
        // sobre todas las empresas cliente (ARCHITECTURE.md §5.6). El scope ya
        // lo dejaría pasar por ser staff; escribirlo deja la intención visible.
        $query = InterviewRequest::acrossCompanies()
            ->with(['company', 'vacancy', 'candidates.candidateProfile.user', 'candidates.candidateProfile.skills']);

        if (isset($validated['state'])) {
            $query->where('state', $validated['state']);
        }
        if (isset($validated['company_id'])) {
            $query->where('company_id', $validated['company_id']);
        }

        $paginator = $query
            ->orderBy('state')
            ->orderBy('submitted_at')
            ->paginate(20);

        return $this->success(
            message: 'Solicitudes de entrevistas.',
            data: StaffInterviewRequestResource::collection($paginator),
            meta: [
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
            ],
        );
    }

    public function show(Request $request, InterviewRequest $interviewRequest): JsonResponse
    {
        $this->authorize('view', $interviewRequest);

        $interviewRequest->load([
            'company',
            'vacancy',
            'candidates.candidateProfile.user',
            'candidates.candidateProfile.skills',
            'candidates.candidateProfile.languages',
        ]);

        return $this->success(
            message: 'Solicitud de entrevistas.',
            data: StaffInterviewRequestResource::make($interviewRequest),
        );
    }

    public function accept(
        Request $request,
        InterviewRequest $interviewRequest,
        InterviewRequestCandidate $candidate,
    ): JsonResponse {
        $this->authorize('resolve', $interviewRequest);

        if ($candidate->interview_request_id !== $interviewRequest->id) {
            abort(HttpStatus::HTTP_NOT_FOUND);
        }

        /** @var User $user */
        $user = $request->user();

        try {
            $this->requests->accept($candidate, $user);
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), status: HttpStatus::HTTP_CONFLICT);
        }

        return $this->respondWithRequest(
            $interviewRequest,
            'Perfil aceptado y presentado a la empresa.',
        );
    }

    public function reject(
        RejectInterviewRequestCandidateRequest $request,
        InterviewRequest $interviewRequest,
        InterviewRequestCandidate $candidate,
    ): JsonResponse {
        $this->authorize('resolve', $interviewRequest);

        if ($candidate->interview_request_id !== $interviewRequest->id) {
            abort(HttpStatus::HTTP_NOT_FOUND);
        }

        /** @var User $user */
        $user = $request->user();

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        try {
            $this->requests->reject($candidate, $user, (string) $data['reason']);
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), status: HttpStatus::HTTP_CONFLICT);
        }

        return $this->respondWithRequest(
            $interviewRequest,
            'Perfil vetado. Se avisó a la empresa con el motivo.',
        );
    }

    private function respondWithRequest(InterviewRequest $interviewRequest, string $message): JsonResponse
    {
        $fresh = $interviewRequest->fresh([
            'company',
            'vacancy',
            'candidates.candidateProfile.user',
            'candidates.candidateProfile.skills',
        ]) ?? $interviewRequest;

        return $this->success(
            message: $message,
            data: StaffInterviewRequestResource::make($fresh),
        );
    }
}
