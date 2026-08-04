<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Response as HttpStatus;

/**
 * Refuses the authenticated surface to accounts that have not verified their
 * email, per ARCHITECTURE.md §8.1 (`register → verify-email → /me/profile → …`).
 *
 * The refusal deliberately reuses the `email_unverified` code that
 * `AuthController::login()` already returns, so the frontend branches on a
 * single contract regardless of which door turned the user away.
 *
 * Never apply this to the routes that recover an unverified account
 * (`/auth/verify-email/*`, `/auth/resend-verification`, `/auth/logout`,
 * `/auth/me`, password reset): gating the escape hatch locks the user out for
 * good.
 */
class EnsureVerifiedEmail
{
    use ApiResponse;

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null) {
            return $this->error(
                message: 'No autenticado.',
                status: HttpStatus::HTTP_UNAUTHORIZED,
            );
        }

        if (! $user->hasVerifiedEmail()) {
            return $this->error(
                message: 'Verifica tu correo antes de continuar. Te enviamos un enlace al registrarte.',
                errors: ['code' => ['email_unverified']],
                status: HttpStatus::HTTP_FORBIDDEN,
            );
        }

        return $next($request);
    }
}
