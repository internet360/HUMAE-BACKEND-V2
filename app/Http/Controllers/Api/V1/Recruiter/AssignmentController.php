<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Recruiter;

use App\Enums\AssignmentStage;
use App\Exceptions\ContractNotSignedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pipeline\AssignCandidateRequest;
use App\Http\Requests\Pipeline\UpdateAssignmentRequest;
use App\Http\Requests\Recruiter\ConfirmFinalSalaryRequest;
use App\Http\Resources\V1\Pipeline\AssignmentResource;
use App\Models\CandidateProfile;
use App\Models\User;
use App\Models\Vacancy;
use App\Models\VacancyAssignment;
use App\Services\PipelineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as HttpStatus;
use Throwable;

class AssignmentController extends Controller
{
    public function __construct(
        private readonly PipelineService $pipeline,
    ) {}

    public function index(Request $request, Vacancy $vacancy): JsonResponse
    {
        $this->authorize('viewAny', [VacancyAssignment::class, $vacancy]);

        $assignments = $vacancy->assignments()
            ->with(['candidateProfile.user'])
            ->orderByDesc('created_at')
            ->get();

        return $this->success(
            message: 'Asignaciones de la vacante.',
            data: AssignmentResource::collection($assignments),
        );
    }

    public function store(AssignCandidateRequest $request, Vacancy $vacancy): JsonResponse
    {
        $this->authorize('create', [VacancyAssignment::class, $vacancy]);

        /** @var User $user */
        $user = $request->user();

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $candidate = CandidateProfile::findOrFail((int) $data['candidate_profile_id']);

        try {
            $assignment = $this->pipeline->assign($vacancy, $candidate, $user);
        } catch (Throwable $e) {
            return $this->error(
                message: $e->getMessage(),
                status: HttpStatus::HTTP_CONFLICT,
            );
        }

        // Campos opcionales
        $assignment->fill(array_intersect_key($data, array_flip([
            'priority', 'score', 'recruiter_notes',
        ])))->save();

        $assignment->load('candidateProfile.user');

        return $this->success(
            message: 'Candidato asignado.',
            data: AssignmentResource::make($assignment),
            status: HttpStatus::HTTP_CREATED,
        );
    }

    public function update(UpdateAssignmentRequest $request, VacancyAssignment $assignment): JsonResponse
    {
        $this->authorize('update', $assignment);

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        if (isset($data['stage'])) {
            /** @var User $actor */
            $actor = $request->user();

            try {
                $this->pipeline->changeStage($assignment, AssignmentStage::from($data['stage']), $actor);
            } catch (Throwable $e) {
                return $this->error(
                    message: $e->getMessage(),
                    status: HttpStatus::HTTP_CONFLICT,
                );
            }
            $assignment = $assignment->fresh() ?? $assignment;
            unset($data['stage']);
        }

        if ($data !== []) {
            $assignment->fill($data)->save();
        }

        $assignment->load('candidateProfile.user');

        return $this->success(
            message: 'Asignación actualizada.',
            data: AssignmentResource::make($assignment),
        );
    }

    /**
     * Captura el sueldo final confirmado. Sólo HUMAE.
     *
     * Va aparte de `update()` y no como un campo más suyo porque no es un dato
     * administrativo: es la base de lo que HUMAE va a cobrar. Con ability propia
     * se puede negar a la empresa —que sí conoce el número— sin negarle el resto
     * de la asignación, y queda firmado por quien lo registró.
     */
    public function confirmFinalSalary(
        ConfirmFinalSalaryRequest $request,
        VacancyAssignment $assignment,
    ): JsonResponse {
        $this->authorize('confirmFinalSalary', $assignment);

        /** @var User $user */
        $user = $request->user();

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $assignment->forceFill([
            'final_salary_amount' => $data['final_salary_amount'],
            'final_salary_period' => $data['final_salary_period'],
            'final_salary_currency_id' => $data['final_salary_currency_id'],
            'final_salary_confirmed_by_user_id' => $user->id,
            'final_salary_confirmed_at' => now(),
        ])->save();

        $assignment->load('candidateProfile.user');

        return $this->success(
            message: 'Sueldo final confirmado.',
            data: AssignmentResource::make($assignment->fresh('candidateProfile.user') ?? $assignment),
        );
    }

    /**
     * Cierra la colocación: mueve a `hired` y devenga los honorarios.
     *
     * Endpoint propio y abierto a las dos partes porque el checklist lo pide
     * así: «desde el dashboard del empleador, o desde el panel del reclutador».
     * `PATCH /assignments/{id}` sigue siendo de HUMAE, así que la empresa no
     * gana de paso la capacidad de mover cualquier otra etapa.
     */
    public function hire(Request $request, VacancyAssignment $assignment): JsonResponse
    {
        $this->authorize('hire', $assignment);

        /** @var User $user */
        $user = $request->user();

        try {
            $this->pipeline->changeStage($assignment, AssignmentStage::Hired, $user);
        } catch (ContractNotSignedException $e) {
            return $this->error(
                $e->getMessage(),
                errors: ['contract' => ['unsigned']],
                status: HttpStatus::HTTP_CONFLICT,
            );
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), status: HttpStatus::HTTP_CONFLICT);
        }

        $fresh = $assignment->fresh(['candidateProfile.user']) ?? $assignment;

        return $this->success(
            message: 'Candidato contratado. Se registró el cargo por colocación.',
            data: AssignmentResource::make($fresh),
        );
    }

    public function destroy(Request $request, VacancyAssignment $assignment): JsonResponse
    {
        $this->authorize('delete', $assignment);
        $assignment->delete();

        return $this->success(message: 'Asignación eliminada.', status: HttpStatus::HTTP_NO_CONTENT);
    }

    public function selectFinalist(Request $request, VacancyAssignment $assignment): JsonResponse
    {
        $this->authorize('selectFinalist', $assignment);

        try {
            $this->pipeline->selectFinalist($assignment);
        } catch (Throwable $e) {
            return $this->error(
                message: $e->getMessage(),
                status: HttpStatus::HTTP_CONFLICT,
            );
        }

        $fresh = $assignment->fresh(['candidateProfile.user']) ?? $assignment;

        return $this->success(
            message: 'Candidato marcado como finalista.',
            data: AssignmentResource::make($fresh),
        );
    }
}
