<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;

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
                'status' => 'active',
            ]);

            $user->assignRole(UserRole::Candidate->value);

            Event::dispatch(new Registered($user));

            return $user;
        });
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
}
