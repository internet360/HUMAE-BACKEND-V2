<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\MembershipStatus;
use App\Enums\UserRole;
use App\Models\User;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Response as HttpStatus;

class EnsureActiveMembership
{
    use ApiResponse;

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null) {
            return $this->error(message: 'No autenticado.', status: HttpStatus::HTTP_UNAUTHORIZED);
        }

        // Solo se aplica a candidatos. Los demás roles pasan.
        if (! $user->hasRole(UserRole::Candidate->value)) {
            return $next($request);
        }

        $hasActive = $user->memberships()
            ->where('status', MembershipStatus::Active->value)
            ->where('expires_at', '>', now())
            ->exists();

        if (! $hasActive) {
            return $this->error(
                message: 'Necesitas una membresía activa para acceder a este recurso.',
                errors: ['membership' => ['membership_inactive']],
                status: HttpStatus::HTTP_PAYMENT_REQUIRED,
            );
        }

        return $next($request);
    }
}
