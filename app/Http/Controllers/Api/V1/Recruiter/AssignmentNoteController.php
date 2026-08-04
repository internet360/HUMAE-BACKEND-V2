<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Recruiter;

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
        $this->authorize('viewNotes', $assignment);

        $query = $assignment->notes()->with('author')->orderByDesc('created_at');

        if (! $this->canReadInternalNotes($request, $assignment)) {
            $query->where('visibility', 'company');
        }

        return $this->success(
            message: 'Notas de la asignación.',
            data: AssignmentNoteResource::collection($query->get()),
        );
    }

    public function store(AssignmentNoteRequest $request, VacancyAssignment $assignment): JsonResponse
    {
        $this->authorize('createNote', $assignment);

        /** @var User $user */
        $user = $request->user();

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        // Only internal staff may file an internal note; a company note is
        // always company-visible, whatever the payload asked for.
        $visibility = $this->canReadInternalNotes($request, $assignment)
            ? ($data['visibility'] ?? 'internal')
            : 'company';

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

    private function canReadInternalNotes(Request $request, VacancyAssignment $assignment): bool
    {
        /** @var User $user */
        $user = $request->user();

        return $user->can('viewInternalNotes', $assignment);
    }
}
