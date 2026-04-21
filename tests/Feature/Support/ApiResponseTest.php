<?php

declare(strict_types=1);

use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class ApiResponseTestCaller
{
    use ApiResponse {
        success as public callSuccess;
        error as public callError;
    }
}

beforeEach(function (): void {
    $this->caller = new ApiResponseTestCaller;
});

it('success() returns envelope with success=true and default message OK', function (): void {
    $response = $this->caller->callSuccess();

    expect($response)->toBeInstanceOf(JsonResponse::class);
    expect($response->getStatusCode())->toBe(200);
    $payload = json_decode((string) $response->getContent(), true);
    expect($payload)->toMatchArray([
        'success' => true,
        'message' => 'OK',
        'data' => null,
        'meta' => null,
    ]);
});

it('success() accepts data and meta', function (): void {
    $response = $this->caller->callSuccess('Creado', ['id' => 1], ['total' => 5], 201);

    expect($response->getStatusCode())->toBe(201);
    $payload = json_decode((string) $response->getContent(), true);
    expect($payload['data'])->toBe(['id' => 1]);
    expect($payload['meta'])->toBe(['total' => 5]);
});

it('success() preserves list arrays (not just associative)', function (): void {
    $response = $this->caller->callSuccess('Listado', [1, 2, 3]);

    $payload = json_decode((string) $response->getContent(), true);
    expect($payload['data'])->toBe([1, 2, 3]);
});

it('error() returns envelope with success=false and status 400 by default', function (): void {
    $response = $this->caller->callError('Algo falló');

    expect($response->getStatusCode())->toBe(400);
    $payload = json_decode((string) $response->getContent(), true);
    expect($payload)->toMatchArray([
        'success' => false,
        'message' => 'Algo falló',
        'errors' => null,
    ]);
});

it('error() includes errors array and custom status', function (): void {
    $response = $this->caller->callError('Validation failed', ['email' => ['required']], 422);

    expect($response->getStatusCode())->toBe(422);
    $payload = json_decode((string) $response->getContent(), true);
    expect($payload['errors'])->toBe(['email' => ['required']]);
});
