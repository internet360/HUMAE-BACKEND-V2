<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Company;

use App\Http\Concerns\ResolvesMyCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\Companies\StoreInterviewRequestRequest;
use App\Http\Resources\V1\Companies\InterviewRequestResource;
use App\Models\InterviewRequest;
use App\Models\User;
use App\Services\InterviewRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as HttpStatus;

/**
 * Solicitudes de entrevistas de la empresa cliente.
 *
 * Los cuatro pasos del flujo del empleador —perfiles elegidos, vacante breve,
 * dos horarios, enviar— entran por un solo `store()`. Partirlo en tres
 * llamadas dejaría estados intermedios que el negocio no reconoce: una vacante
 * sin solicitud no es nada que HUMAE deba atender.
 */
class InterviewRequestController extends Controller
{
    use ResolvesMyCompany;

    public function __construct(
        private readonly InterviewRequestService $requests,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', InterviewRequest::class);

        /** @var User $user */
        $user = $request->user();

        $companyIds = $user->companyMemberships()->pluck('company_id');

        $paginator = InterviewRequest::query()
            ->whereIn('company_id', $companyIds)
            ->with(['vacancy', 'candidates.candidateProfile.skills', 'candidates.candidateProfile.languages'])
            ->orderByDesc('submitted_at')
            ->paginate(20);

        return $this->success(
            message: 'Tus solicitudes de entrevistas.',
            data: InterviewRequestResource::collection($paginator),
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

    public function store(StoreInterviewRequestRequest $request): JsonResponse
    {
        $this->authorize('create', InterviewRequest::class);

        /** @var User $user */
        $user = $request->user();

        [$company] = $this->resolveCompany($user);

        if ($company === null) {
            return $this->error(
                'Tu cuenta no está vinculada a una empresa.',
                status: HttpStatus::HTTP_NOT_FOUND,
            );
        }

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $slots = array_values(array_map(
            static fn (mixed $slot): string => (string) $slot,
            (array) $data['interview_slots'],
        ));

        $references = array_values(array_map(
            static fn (mixed $reference): string => (string) $reference,
            (array) $data['candidate_references'],
        ));

        try {
            $interviewRequest = $this->requests->submit(
                requester: $user,
                company: $company,
                vacancyData: (array) $data['vacancy'],
                candidateReferences: $references,
                slots: $slots,
                timezone: $data['timezone'] ?? null,
                note: $data['note'] ?? null,
            );
        } catch (RuntimeException $e) {
            // Perfiles que dejaron de estar disponibles entre que se armó la
            // lista y se envió. Es 422 y no 500: el cliente puede arreglarlo
            // recargando y volviendo a elegir.
            return $this->error(
                $e->getMessage(),
                errors: ['candidate_references' => [$e->getMessage()]],
                status: HttpStatus::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $interviewRequest->load([
            'vacancy',
            'candidates.candidateProfile.skills',
            'candidates.candidateProfile.languages',
        ]);

        return $this->success(
            message: 'Solicitud enviada. HUMAE la revisará y coordinará las entrevistas.',
            data: InterviewRequestResource::make($interviewRequest),
            status: HttpStatus::HTTP_CREATED,
        );
    }

    public function show(Request $request, InterviewRequest $interviewRequest): JsonResponse
    {
        $this->authorize('view', $interviewRequest);

        $interviewRequest->load([
            'vacancy',
            'candidates.candidateProfile.skills',
            'candidates.candidateProfile.languages',
        ]);

        return $this->success(
            message: 'Solicitud de entrevistas.',
            data: InterviewRequestResource::make($interviewRequest),
        );
    }
}
