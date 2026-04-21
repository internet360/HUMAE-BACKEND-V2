<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Candidate;

use App\Enums\LanguageLevel;
use App\Http\Controllers\Api\V1\Candidate\Concerns\ResolvesCandidateProfile;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Profile\LanguagePivotResource;
use App\Models\Language;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response as HttpStatus;

class LanguageController extends Controller
{
    use ResolvesCandidateProfile;

    public function index(Request $request): JsonResponse
    {
        $items = $this->profile($request)->languages()->orderBy('name')->get();

        return $this->success(
            message: 'Idiomas del candidato.',
            data: LanguagePivotResource::collection($items),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $levels = array_map(fn (LanguageLevel $level) => $level->value, LanguageLevel::cases());

        $validated = $request->validate([
            'language_id' => ['required', 'integer', 'exists:languages,id'],
            'level' => ['required', Rule::in($levels)],
        ]);

        $profile = $this->profile($request);

        $profile->languages()->syncWithoutDetaching([
            $validated['language_id'] => [
                'level' => $validated['level'],
            ],
        ]);

        $language = $profile->languages()->where('languages.id', $validated['language_id'])->first();

        return $this->success(
            message: 'Idioma agregado.',
            data: $language !== null ? LanguagePivotResource::make($language) : null,
            status: HttpStatus::HTTP_CREATED,
        );
    }

    public function destroy(Request $request, Language $language): JsonResponse
    {
        $this->profile($request)->languages()->detach($language->id);

        return $this->success(message: 'Idioma eliminado.', status: HttpStatus::HTTP_NO_CONTENT);
    }
}
