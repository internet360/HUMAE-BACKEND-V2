<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Candidate;

use App\Http\Controllers\Controller;
use App\Http\Requests\Candidate\UpdateProfileRequest;
use App\Http\Resources\V1\Profile\CandidateProfileResource;
use App\Models\User;
use App\Services\ProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CandidateProfileController extends Controller
{
    public function __construct(
        private readonly ProfileService $service,
    ) {}

    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $profile = $this->service->findOrCreate($user);

        $profile->load([
            'experiences' => fn ($q) => $q->orderBy('sort_order')->orderByDesc('start_date'),
            'educations' => fn ($q) => $q->orderBy('sort_order')->orderByDesc('end_date'),
            'courses' => fn ($q) => $q->orderBy('sort_order'),
            'certifications' => fn ($q) => $q->orderBy('sort_order'),
            'references' => fn ($q) => $q->orderBy('sort_order'),
            'documents',
            'skills',
            'languages',
            'functionalAreas',
        ]);

        return $this->success(
            message: 'Perfil del candidato.',
            data: CandidateProfileResource::make($profile),
        );
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $profile = $this->service->findOrCreate($user);

        /** @var array<string, mixed> $data */
        $data = $request->validated();
        $updated = $this->service->update($profile, $data);
        $updated->load('functionalAreas');

        return $this->success(
            message: 'Perfil actualizado.',
            data: CandidateProfileResource::make($updated),
        );
    }
}
