<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Shared;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

final class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $database = $this->checkDatabase();

        $healthy = $database['ok'];

        $payload = [
            'app' => config('app.name'),
            'environment' => config('app.env'),
            'version' => '1.0.0',
            'checks' => [
                'database' => $database,
            ],
        ];

        if (! $healthy) {
            return response()->json([
                'success' => false,
                'message' => 'degraded',
                'errors' => null,
                'data' => $payload,
            ], 503);
        }

        return $this->success(message: 'healthy', data: $payload);
    }

    /**
     * @return array{ok: bool, driver: string, error?: string}
     */
    private function checkDatabase(): array
    {
        try {
            DB::connection()->getPdo();

            return [
                'ok' => true,
                'driver' => config('database.default'),
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'driver' => config('database.default'),
                'error' => config('app.debug') ? $e->getMessage() : 'connection failed',
            ];
        }
    }
}
