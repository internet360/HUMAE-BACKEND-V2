<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Shared;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as HttpStatus;

/**
 * Devuelve la lista de reclutadores HUMAE activos que pueden ser asignados
 * como responsables de una vacante. Accesible para recruiter, company_user y admin.
 */
final class AvailableRecruitersController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();
        if ($user === null) {
            return $this->error('No autenticado.', status: HttpStatus::HTTP_UNAUTHORIZED);
        }

        if (! $user->hasAnyRole([
            UserRole::Recruiter->value,
            UserRole::CompanyUser->value,
            UserRole::Admin->value,
        ])) {
            return $this->error('No tienes acceso a este recurso.', status: HttpStatus::HTTP_FORBIDDEN);
        }

        $recruiters = User::query()
            ->whereHas('roles', fn ($q) => $q->where('name', UserRole::Recruiter->value))
            ->where('status', UserStatus::Active->value)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'avatar_url']);

        return $this->success(
            message: 'Reclutadores disponibles.',
            data: $recruiters->map(fn (User $r): array => [
                'id' => $r->id,
                'name' => $r->name,
                'email' => $r->email,
                'avatar_url' => $r->avatar_url,
            ])->values(),
        );
    }
}
