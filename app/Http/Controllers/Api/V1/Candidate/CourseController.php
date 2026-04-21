<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Candidate;

use App\Http\Controllers\Api\V1\Candidate\Concerns\ResolvesCandidateProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Candidate\CourseRequest;
use App\Http\Resources\V1\Profile\CourseResource;
use App\Models\CandidateCourse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as HttpStatus;

class CourseController extends Controller
{
    use ResolvesCandidateProfile;

    public function index(Request $request): JsonResponse
    {
        $items = $this->profile($request)
            ->courses()
            ->orderBy('sort_order')
            ->orderByDesc('completed_at')
            ->get();

        return $this->success(
            message: 'Cursos.',
            data: CourseResource::collection($items),
        );
    }

    public function store(CourseRequest $request): JsonResponse
    {
        $course = $this->profile($request)->courses()->create($request->validated());

        return $this->success(
            message: 'Curso agregado.',
            data: CourseResource::make($course),
            status: HttpStatus::HTTP_CREATED,
        );
    }

    public function update(CourseRequest $request, CandidateCourse $course): JsonResponse
    {
        $this->ensureOwned($request, $course->candidate_profile_id);
        $course->update($request->validated());

        return $this->success(
            message: 'Curso actualizado.',
            data: CourseResource::make($course->fresh()),
        );
    }

    public function destroy(Request $request, CandidateCourse $course): JsonResponse
    {
        $this->ensureOwned($request, $course->candidate_profile_id);
        $course->delete();

        return $this->success(message: 'Curso eliminado.', status: HttpStatus::HTTP_NO_CONTENT);
    }
}
