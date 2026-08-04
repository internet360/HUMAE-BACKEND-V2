<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Notifications\PendingUserRegistrationNotification;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

class AuthService
{
    /**
     * Registers a new candidate user and assigns the `candidate` role.
     *
     * @param  array{name: string, email: string, password: string, phone?: string|null}  $data
     */
    public function registerCandidate(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => Hash::make($data['password']),
                'status' => UserStatus::Active->value,
            ]);

            $user->assignRole(UserRole::Candidate->value);

            Event::dispatch(new Registered($user));

            return $user;
        });
    }

    /**
     * Self-service de reclutador. Crea User en `pending_approval`, dispara
     * verify-email y notifica a todos los admins para que revisen y aprueben.
     *
     * @param  array{name: string, email: string, password: string, phone?: string|null, motivo?: string|null}  $data
     */
    public function registerRecruiter(array $data): User
    {
        $user = DB::transaction(function () use ($data): User {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => Hash::make($data['password']),
                'status' => UserStatus::PendingApproval->value,
            ]);

            $user->assignRole(UserRole::Recruiter->value);

            Event::dispatch(new Registered($user));

            return $user;
        });

        $this->notifyAdminsOfPendingRegistration(
            user: $user,
            roleLabel: 'Reclutador',
            companyName: null,
            reason: $data['motivo'] ?? null,
        );

        return $user;
    }

    public function issueToken(User $user, ?string $deviceName = null): string
    {
        $deviceName = $deviceName ?: 'api';

        return $user->createToken($deviceName)->plainTextToken;
    }

    public function revokeCurrentToken(User $user): void
    {
        $token = $user->currentAccessToken();

        if ($token !== null && method_exists($token, 'delete')) {
            $token->delete();
        }
    }

    public function markLoggedIn(User $user): void
    {
        $user->forceFill(['last_login_at' => now()])->save();
    }

    /**
     * Notifica a todos los admins que un usuario está pendiente de aprobación.
     */
    private function notifyAdminsOfPendingRegistration(
        User $user,
        string $roleLabel,
        ?string $companyName,
        ?string $reason,
    ): void {
        $admins = User::role(UserRole::Admin->value)->get();

        if ($admins->isEmpty()) {
            return;
        }

        Notification::send(
            $admins,
            new PendingUserRegistrationNotification(
                applicantName: $user->name,
                applicantEmail: $user->email,
                roleLabel: $roleLabel,
                companyName: $companyName,
                reason: $reason,
            ),
        );
    }
}
