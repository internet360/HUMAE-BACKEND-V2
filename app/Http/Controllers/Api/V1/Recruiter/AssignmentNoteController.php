<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Recruiter;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pipeline\AssignmentNoteRequest;
use App\Http\Resources\V1\Pipeline\AssignmentNoteResource;
use App\Models\User;
use App\Models\VacancyAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as HttpStatus;

class AssignmentNoteController extends Controller
{
    public function index(Request $request, VacancyAssignment $assignment): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $this->canAccessAssignment($user, $assignment)) {
            return $this->error(
                'No tienes acceso a las notas de esta asignación.',
                status: HttpStatus::HTTP_FORBIDDEN,
            );
        }

        $query = $assignment->notes()->with('author')->orderByDesc('created_at');

        // Company_user solo ve notas visibles para empresa
        if ($this->isCompanyOnly($user)) {
            $query->where('visibility', 'company');
        }

        return $this->success(
            message: 'Notas de la asignación.',
            data: AssignmentNoteResource::collection($query->get()),
        );
    }

    public function store(AssignmentNoteRequest $request, VacancyAssignment $assignment): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $this->canAccessAssignment($user, $assignment)) {
            return $this->error(
                'No puedes crear notas para esta asignación.',
                status: HttpStatus::HTTP_FORBIDDEN,
            );
        }

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        // Company_user siempre crea con visibility=company (no puede forzar internal).
        $visibility = $this->isCompanyOnly($user)
            ? 'company'
            : ($data['visibility'] ?? 'internal');

        $note = $assignment->notes()->create([
            'author_id' => $user->id,
            'visibility' => $visibility,
            'body' => $data['body'],
        ]);

        $note->load('author');

        return $this->success(
            message: 'Nota agregada.',
            data: AssignmentNoteResource::make($note),
            status: HttpStatus::HTTP_CREATED,
        );
    }

    private function canAccessAssignment(User $user, VacancyAssignment $assignment): bool
    {
        if ($user->hasAnyRole([UserRole::Recruiter->value, UserRole::Admin->value])) {
            return true;
        }

        if ($user->hasRole(UserRole::CompanyUser->value)) {
            $vacancy = $assignment->vacancy;
            $company = $vacancy?->company;

            return $company !== null
                && $company->members()->where('user_id', $user->id)->exists();
        }

        return false;
    }

    private function isCompanyOnly(User $user): bool
    {
        return $user->hasRole(UserRole::CompanyUser->value)
            && ! $user->hasAnyRole([UserRole::Recruiter->value, UserRole::Admin->value]);
    }
}
