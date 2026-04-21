<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Response as HttpStatus;

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
                message: 'Debes verificar tu email antes de continuar.',
                errors: ['email' => ['email_not_verified']],
                status: HttpStatus::HTTP_FORBIDDEN,
            );
        }

        return $next($request);
    }
}
