<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response as HttpStatus;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

final class ApiExceptionHandler
{
    public static function register(Exceptions $exceptions): void
    {
        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! self::wantsJson($request)) {
                return null;
            }

            return self::envelope(
                message: 'La validación falló.',
                errors: $e->errors(),
                status: HttpStatus::HTTP_UNPROCESSABLE_ENTITY,
            );
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if (! self::wantsJson($request)) {
                return null;
            }

            return self::envelope(
                message: 'No autenticado.',
                status: HttpStatus::HTTP_UNAUTHORIZED,
            );
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) {
            if (! self::wantsJson($request)) {
                return null;
            }

            return self::envelope(
                message: $e->getMessage() ?: 'No autorizado.',
                status: HttpStatus::HTTP_FORBIDDEN,
            );
        });

        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            if (! self::wantsJson($request)) {
                return null;
            }

            return self::envelope(
                message: 'Recurso no encontrado.',
                status: HttpStatus::HTTP_NOT_FOUND,
            );
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if (! self::wantsJson($request)) {
                return null;
            }

            return self::envelope(
                message: 'Ruta no encontrada.',
                status: HttpStatus::HTTP_NOT_FOUND,
            );
        });

        $exceptions->render(function (TooManyRequestsHttpException $e, Request $request) {
            if (! self::wantsJson($request)) {
                return null;
            }

            return self::envelope(
                message: 'Demasiadas peticiones. Intenta más tarde.',
                status: HttpStatus::HTTP_TOO_MANY_REQUESTS,
            );
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! self::wantsJson($request)) {
                return null;
            }

            $status = $e instanceof HttpExceptionInterface
                ? $e->getStatusCode()
                : HttpStatus::HTTP_INTERNAL_SERVER_ERROR;

            $message = config('app.debug')
                ? $e->getMessage()
                : 'Error interno del servidor.';

            return self::envelope(message: $message, status: $status);
        });
    }

    private static function wantsJson(Request $request): bool
    {
        return $request->expectsJson() || $request->is('api/*');
    }

    /**
     * @param  array<string, mixed>|null  $errors
     */
    private static function envelope(string $message, ?array $errors = null, int $status = HttpStatus::HTTP_INTERNAL_SERVER_ERROR): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
        ], $status);
    }
}
