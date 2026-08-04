<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Shared;

use App\Enums\TutorialChannel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Shared\CompleteTutorialRequest;
use App\Http\Resources\V1\TutorialStateResource;
use App\Models\User;
use App\Services\TutorialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * One-time welcome tutorial per role (Fase 16 §5.1). Thin by design: all the
 * versioning/applicability logic lives in TutorialService.
 */
class TutorialController extends Controller
{
    public function __construct(private readonly TutorialService $tutorials) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->success(
            message: 'Tutoriales disponibles para tu rol.',
            data: TutorialStateResource::collection($this->tutorials->statusForUser($user)),
        );
    }

    public function complete(CompleteTutorialRequest $request, string $key): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var string $channelValue */
        $channelValue = $request->validated('channel');

        $state = $this->tutorials->complete($user, $key, TutorialChannel::from($channelValue));

        return $this->success(
            message: 'Tutorial marcado como completado.',
            data: TutorialStateResource::make($this->tutorials->present($key, $state)),
        );
    }

    public function skip(Request $request, string $key): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $state = $this->tutorials->skip($user, $key);

        return $this->success(
            message: 'Tutorial omitido.',
            data: TutorialStateResource::make($this->tutorials->present($key, $state)),
        );
    }
}
