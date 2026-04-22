<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\UserResource;
use App\Models\Company;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\Response as HttpStatus;

class InvitationController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    public function show(string $token): JsonResponse
    {
        $user = $this->findByToken($token);
        if ($user === null) {
            return $this->error(
                'Invitación inválida o caducada.',
                status: HttpStatus::HTTP_NOT_FOUND,
            );
        }

        /** @var Company|null $company */
        $company = $user->companyMemberships()->with('company')->first()?->company;

        /** @var Role|null $role */
        $role = $user->roles->first();

        return $this->success(
            message: 'Invitación vigente.',
            data: [
                'email' => $user->email,
                'name' => $user->name,
                'role' => $role?->name,
                'company' => $company !== null ? [
                    'legal_name' => $company->legal_name,
                    'trade_name' => $company->trade_name,
                ] : null,
                'expires_at' => $user->invitation_expires_at?->toIso8601String(),
            ],
        );
    }

    public function accept(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'min:32', 'max:80'],
            'password' => ['required', 'string', 'min:8', 'max:200', 'confirmed'],
        ]);

        $user = $this->findByToken($validated['token']);
        if ($user === null) {
            return $this->error(
                'Invitación inválida o caducada.',
                status: HttpStatus::HTTP_NOT_FOUND,
            );
        }

        $user->forceFill([
            'password' => Hash::make($validated['password']),
            'invitation_token' => null,
            'invitation_expires_at' => null,
            'invitation_accepted_at' => now(),
            'status' => 'active',
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        // Marca CompanyMember como aceptado si existía
        $user->companyMemberships()
            ->whereNull('accepted_at')
            ->update(['accepted_at' => now()]);

        $token = $this->auth->issueToken($user, $request->userAgent() ?? 'invitation');

        return $this->success(
            message: 'Cuenta activada.',
            data: [
                'user' => new UserResource($user->load('roles', 'permissions')),
                'token' => $token,
                'token_type' => 'Bearer',
            ],
        );
    }

    private function findByToken(string $plainToken): ?User
    {
        $hashed = hash('sha256', $plainToken);

        /** @var User|null $user */
        $user = User::where('invitation_token', $hashed)
            ->where(function ($q): void {
                $q->whereNull('invitation_expires_at')
                    ->orWhere('invitation_expires_at', '>', now());
            })
            ->first();

        return $user;
    }
}
