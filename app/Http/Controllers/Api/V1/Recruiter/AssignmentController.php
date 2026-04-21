<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Recruiter;

use App\Enums\AssignmentStage;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pipeline\AssignCandidateRequest;
use App\Http\Requests\Pipeline\UpdateAssignmentRequest;
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
        $this->authorize('view', $vacancy);

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
        $this->authorize('update', $vacancy);

        /** @var User $user */
        $user = $request->user();

        if (! $user->hasAnyRole([UserRole::Recruiter->value, UserRole::Admin->value])) {
            return $this->error(
                'Solo reclutadores pueden asignar candidatos.',
                status: HttpStatus::HTTP_FORBIDDEN,
            );
        }

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
        $this->authorizeAccess($request, $assignment);

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        if (isset($data['stage'])) {
            try {
                $this->pipeline->changeStage($assignment, AssignmentStage::from($data['stage']));
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

    public function destroy(Request $request, VacancyAssignment $assignment): JsonResponse
    {
        $this->authorizeRecruiter($request);
        $assignment->delete();

        return $this->success(message: 'Asignación eliminada.', status: HttpStatus::HTTP_NO_CONTENT);
    }

    public function selectFinalist(Request $request, VacancyAssignment $assignment): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Company_user solo puede marcar finalista en sus propias vacantes;
        // recruiter/admin también pueden.
        $vacancy = $assignment->vacancy;
        if ($vacancy === null) {
            return $this->error('Asignación inválida.', status: HttpStatus::HTTP_NOT_FOUND);
        }

        $isRecruiterOrAdmin = $user->hasAnyRole([
            UserRole::Recruiter->value,
            UserRole::Admin->value,
        ]);

        $isCompanyOwner = $user->hasRole(UserRole::CompanyUser->value)
            && $vacancy->company?->members()
                ->where('user_id', $user->id)
                ->whereIn('role', ['owner', 'manager'])
                ->exists();

        if (! $isRecruiterOrAdmin && ! $isCompanyOwner) {
            return $this->error(
                'No tienes permisos para seleccionar finalista.',
                status: HttpStatus::HTTP_FORBIDDEN,
            );
        }

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

    private function authorizeAccess(Request $request, VacancyAssignment $assignment): void
    {
        $vacancy = $assignment->vacancy;
        if ($vacancy === null) {
            abort(HttpStatus::HTTP_NOT_FOUND);
        }

        $this->authorize('update', $vacancy);
    }

    private function authorizeRecruiter(Request $request): void
    {
        /** @var User|null $user */
        $user = $request->user();
        if ($user === null) {
            abort(HttpStatus::HTTP_UNAUTHORIZED);
        }
        if (! $user->hasAnyRole([UserRole::Recruiter->value, UserRole::Admin->value])) {
            abort(HttpStatus::HTTP_FORBIDDEN);
        }
    }
}
