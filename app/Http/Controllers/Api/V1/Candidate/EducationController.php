<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Candidate;

use App\Http\Controllers\Api\V1\Candidate\Concerns\ResolvesCandidateProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Candidate\EducationRequest;
use App\Http\Resources\V1\Profile\EducationResource;
use App\Models\CandidateEducation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as HttpStatus;

class EducationController extends Controller
{
    use ResolvesCandidateProfile;

    public function index(Request $request): JsonResponse
    {
        $items = $this->profile($request)
            ->educations()
            ->orderBy('sort_order')
            ->orderByDesc('end_date')
            ->get();

        return $this->success(
            message: 'Estudios formales.',
            data: EducationResource::collection($items),
        );
    }

    public function store(EducationRequest $request): JsonResponse
    {
        $education = $this->profile($request)->educations()->create($request->validated());

        return $this->success(
            message: 'Estudio agregado.',
            data: EducationResource::make($education),
            status: HttpStatus::HTTP_CREATED,
        );
    }

    public function update(EducationRequest $request, CandidateEducation $education): JsonResponse
    {
        $this->ensureOwned($request, $education->candidate_profile_id);
        $education->update($request->validated());

        return $this->success(
            message: 'Estudio actualizado.',
            data: EducationResource::make($education->fresh()),
        );
    }

    public function destroy(Request $request, CandidateEducation $education): JsonResponse
    {
        $this->ensureOwned($request, $education->candidate_profile_id);
        $education->delete();

        return $this->success(message: 'Estudio eliminado.', status: HttpStatus::HTTP_NO_CONTENT);
    }
}
