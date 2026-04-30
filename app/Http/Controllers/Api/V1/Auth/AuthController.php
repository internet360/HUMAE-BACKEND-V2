<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterCompanyRequest;
use App\Http\Requests\Auth\RegisterRecruiterRequest;
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

    public function registerRecruiter(RegisterRecruiterRequest $request): JsonResponse
    {
        /** @var array{name: string, email: string, password: string, phone?: string|null, motivo?: string|null} $data */
        $data = $request->validated();

        $user = $this->auth->registerRecruiter($data);

        return $this->success(
            message: 'Recibimos tu solicitud. Te enviamos un correo para verificar tu email; un administrador de HUMAE revisará tu cuenta y te avisará cuando esté aprobada.',
            data: [
                'user' => new UserResource($user->load('roles', 'permissions')),
                'pending_approval' => true,
            ],
            status: HttpStatus::HTTP_CREATED,
        );
    }

    public function registerCompany(RegisterCompanyRequest $request): JsonResponse
    {
        /** @var array<string, mixed> $payload */
        $payload = $request->validated();

        /** @var array{name: string, email: string, password: string, phone?: string|null} $userData */
        $userData = [
            'name' => (string) $payload['name'],
            'email' => (string) $payload['email'],
            'password' => (string) $payload['password'],
            'phone' => $payload['phone'] ?? null,
        ];

        /** @var array<string, mixed> $rawCompany */
        $rawCompany = $payload['company'] ?? [];

        /** @var array{legal_name: string, trade_name?: string|null, rfc?: string|null, website?: string|null, contact_name?: string|null, contact_email?: string|null, contact_phone?: string|null, industry_id?: int|null, company_size_id?: int|null, motivo?: string|null} $companyData */
        $companyData = [
            'legal_name' => (string) $rawCompany['legal_name'],
            'trade_name' => isset($rawCompany['trade_name']) ? (string) $rawCompany['trade_name'] : null,
            'rfc' => isset($rawCompany['rfc']) ? (string) $rawCompany['rfc'] : null,
            'website' => isset($rawCompany['website']) ? (string) $rawCompany['website'] : null,
            'contact_name' => isset($rawCompany['contact_name']) ? (string) $rawCompany['contact_name'] : null,
            'contact_email' => isset($rawCompany['contact_email']) ? (string) $rawCompany['contact_email'] : null,
            'contact_phone' => isset($rawCompany['contact_phone']) ? (string) $rawCompany['contact_phone'] : null,
            'industry_id' => isset($rawCompany['industry_id']) ? (int) $rawCompany['industry_id'] : null,
            'company_size_id' => isset($rawCompany['company_size_id']) ? (int) $rawCompany['company_size_id'] : null,
            'motivo' => isset($rawCompany['motivo']) ? (string) $rawCompany['motivo'] : null,
        ];

        $user = $this->auth->registerCompanyUser($userData, $companyData);

        return $this->success(
            message: 'Recibimos el registro de tu empresa. Te enviamos un correo para verificar tu email; un administrador de HUMAE revisará la cuenta y te avisará cuando esté aprobada.',
            data: [
                'user' => new UserResource($user->load('roles', 'permissions')),
                'pending_approval' => true,
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

        if ($user->email_verified_at === null) {
            return $this->error(
                message: 'Verifica tu correo antes de iniciar sesión. Te enviamos un enlace al registrarte.',
                errors: ['code' => ['email_unverified']],
                status: HttpStatus::HTTP_FORBIDDEN,
            );
        }

        if ($user->status === UserStatus::PendingApproval->value) {
            return $this->error(
                message: 'Tu cuenta está en revisión por un administrador de HUMAE. Te avisaremos por correo cuando esté aprobada.',
                errors: ['code' => ['pending_approval']],
                status: HttpStatus::HTTP_FORBIDDEN,
            );
        }

        if ($user->status !== UserStatus::Active->value) {
            return $this->error(
                message: 'Tu cuenta está inactiva. Contacta a soporte.',
                errors: ['code' => ['account_inactive']],
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
