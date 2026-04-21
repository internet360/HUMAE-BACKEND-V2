<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response as HttpStatus;

trait ApiResponse
{
    /**
     * Sends a success envelope.
     *
     * @param  array<array-key, mixed>|\JsonSerializable|null  $data
     * @param  array<string, mixed>  $meta
     */
    protected function success(
        string $message = 'OK',
        mixed $data = null,
        array $meta = [],
        int $status = HttpStatus::HTTP_OK,
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'meta' => $meta ?: null,
        ], $status);
    }

    /**
     * Sends an error envelope.
     *
     * @param  array<string, mixed>  $errors
     */
    protected function error(
        string $message,
        array $errors = [],
        int $status = HttpStatus::HTTP_BAD_REQUEST,
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors ?: null,
        ], $status);
    }
}
