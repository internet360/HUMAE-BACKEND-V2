<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Candidate\Concerns;

use App\Models\CandidateProfile;
use App\Models\User;
use App\Services\ProfileService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Symfony\Component\HttpFoundation\Response as HttpStatus;

trait ResolvesCandidateProfile
{
    protected function profile(Request $request): CandidateProfile
    {
        /** @var User $user */
        $user = $request->user();

        return app(ProfileService::class)->findOrCreate($user);
    }

    /**
     * Verifica que el recurso pertenezca al perfil del usuario autenticado.
     */
    protected function ensureOwned(Request $request, int $profileIdOnResource): void
    {
        if ($profileIdOnResource !== $this->profile($request)->id) {
            abort(HttpStatus::HTTP_NOT_FOUND);
        }
    }

    /**
     * @template T
     *
     * @param  T  $resourceInstance
     * @return T
     */
    protected function resource(mixed $resourceInstance): mixed
    {
        return $resourceInstance instanceof JsonResource ? $resourceInstance : $resourceInstance;
    }
}
