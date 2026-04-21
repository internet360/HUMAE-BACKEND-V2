<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\V1\UserResource;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response as HttpStatus;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        /** @var array{name: string, email: string, password: string, phone?: string|null} $data */
        $data = $request->validated();

        $user = $this->auth->registerCandidate($data);
        $token = $this->auth->issueToken($user, $request->userAgent() ?? 'api');

        return $this->success(
            message: 'Registro exitoso. Revisa tu email para verificar la cuenta.',
            data: [
                'user' => new UserResource($user->load('roles', 'permissions')),
                'token' => $token,
                'token_type' => 'Bearer',
            ],
            status: HttpStatus::HTTP_CREATED,
        );
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->string('email'))->first();

        if ($user === null || ! Hash::check((string) $request->input('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Las credenciales no coinciden con nuestros registros.'],
            ]);
        }

        if ($user->status !== 'active') {
            return $this->error(
                message: 'Tu cuenta está inactiva. Contacta a soporte.',
                status: HttpStatus::HTTP_FORBIDDEN,
            );
        }

        $this->auth->markLoggedIn($user);

        $deviceName = (string) ($request->input('device_name') ?? $request->userAgent() ?? 'api');
        $token = $this->auth->issueToken($user, $deviceName);

        return $this->success(
            message: 'Sesión iniciada.',
            data: [
                'user' => new UserResource($user->load('roles', 'permissions')),
                'token' => $token,
                'token_type' => 'Bearer',
            ],
        );
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user !== null) {
            $this->auth->revokeCurrentToken($user);
        }

        return $this->success(message: 'Sesión cerrada.', status: HttpStatus::HTTP_NO_CONTENT);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->success(
            message: 'Usuario autenticado.',
            data: new UserResource($user->load('roles', 'permissions')),
        );
    }
}
