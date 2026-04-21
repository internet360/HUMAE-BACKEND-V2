<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Candidate;

use App\Http\Controllers\Api\V1\Candidate\Concerns\ResolvesCandidateProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Candidate\ExperienceRequest;
use App\Http\Resources\V1\Profile\ExperienceResource;
use App\Models\CandidateExperience;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as HttpStatus;

class ExperienceController extends Controller
{
    use ResolvesCandidateProfile;

    public function index(Request $request): JsonResponse
    {
        $profile = $this->profile($request);

        $items = $profile->experiences()
            ->orderBy('sort_order')
            ->orderByDesc('start_date')
            ->get();

        return $this->success(
            message: 'Experiencias laborales.',
            data: ExperienceResource::collection($items),
        );
    }

    public function store(ExperienceRequest $request): JsonResponse
    {
        $profile = $this->profile($request);

        $experience = $profile->experiences()->create($request->validated());

        return $this->success(
            message: 'Experiencia agregada.',
            data: ExperienceResource::make($experience),
            status: HttpStatus::HTTP_CREATED,
        );
    }

    public function update(ExperienceRequest $request, CandidateExperience $experience): JsonResponse
    {
        $this->ensureOwned($request, $experience->candidate_profile_id);
        $experience->update($request->validated());

        return $this->success(
            message: 'Experiencia actualizada.',
            data: ExperienceResource::make($experience->fresh()),
        );
    }

    public function destroy(Request $request, CandidateExperience $experience): JsonResponse
    {
        $this->ensureOwned($request, $experience->candidate_profile_id);
        $experience->delete();

        return $this->success(message: 'Experiencia eliminada.', status: HttpStatus::HTTP_NO_CONTENT);
    }
}
