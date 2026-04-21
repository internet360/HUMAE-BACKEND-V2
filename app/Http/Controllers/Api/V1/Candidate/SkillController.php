<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Candidate;

use App\Enums\SkillLevel;
use App\Http\Controllers\Api\V1\Candidate\Concerns\ResolvesCandidateProfile;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Profile\SkillPivotResource;
use App\Models\Skill;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response as HttpStatus;

class SkillController extends Controller
{
    use ResolvesCandidateProfile;

    public function index(Request $request): JsonResponse
    {
        $items = $this->profile($request)->skills()->orderBy('name')->get();

        return $this->success(
            message: 'Habilidades del candidato.',
            data: SkillPivotResource::collection($items),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $levels = array_map(fn (SkillLevel $level) => $level->value, SkillLevel::cases());

        $validated = $request->validate([
            'skill_id' => ['required', 'integer', 'exists:skills,id'],
            'level' => ['required', Rule::in($levels)],
            'years_of_experience' => ['nullable', 'integer', 'min:0', 'max:70'],
        ]);

        $profile = $this->profile($request);

        $profile->skills()->syncWithoutDetaching([
            $validated['skill_id'] => [
                'level' => $validated['level'],
                'years_of_experience' => $validated['years_of_experience'] ?? null,
            ],
        ]);

        $skill = $profile->skills()->where('skills.id', $validated['skill_id'])->first();

        return $this->success(
            message: 'Habilidad agregada.',
            data: $skill !== null ? SkillPivotResource::make($skill) : null,
            status: HttpStatus::HTTP_CREATED,
        );
    }

    public function destroy(Request $request, Skill $skill): JsonResponse
    {
        $this->profile($request)->skills()->detach($skill->id);

        return $this->success(message: 'Habilidad eliminada.', status: HttpStatus::HTTP_NO_CONTENT);
    }
}
