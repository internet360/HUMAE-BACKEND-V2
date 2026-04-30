<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CompanyMemberRole;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Company;
use App\Models\User;
use App\Notifications\PendingUserRegistrationNotification;
use Cocur\Slugify\Slugify;
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

    /**
     * Self-service de empresa. Crea User (pending_approval) + Company (pending)
     * + CompanyMember pivote como `owner`. Notifica a todos los admins.
     *
     * @param  array{name: string, email: string, password: string, phone?: string|null}  $userData
     * @param  array{legal_name: string, trade_name?: string|null, rfc?: string|null, website?: string|null, contact_name?: string|null, contact_email?: string|null, contact_phone?: string|null, industry_id?: int|null, company_size_id?: int|null, motivo?: string|null}  $companyData
     */
    public function registerCompanyUser(array $userData, array $companyData): User
    {
        /** @var array{user: User, company: Company} $result */
        $result = DB::transaction(function () use ($userData, $companyData): array {
            $user = User::create([
                'name' => $userData['name'],
                'email' => $userData['email'],
                'phone' => $userData['phone'] ?? null,
                'password' => Hash::make($userData['password']),
                'status' => UserStatus::PendingApproval->value,
            ]);

            $user->assignRole(UserRole::CompanyUser->value);

            $slugify = new Slugify;
            $baseSlug = $slugify->slugify($companyData['legal_name']);
            $slug = $baseSlug !== '' ? $baseSlug : 'empresa';
            $i = 1;
            while (Company::where('slug', $slug)->exists()) {
                $slug = $baseSlug.'-'.(++$i);
            }

            $company = Company::create([
                'legal_name' => $companyData['legal_name'],
                'trade_name' => $companyData['trade_name'] ?? null,
                'slug' => $slug,
                'rfc' => $companyData['rfc'] ?? null,
                'website' => $companyData['website'] ?? null,
                'industry_id' => $companyData['industry_id'] ?? null,
                'company_size_id' => $companyData['company_size_id'] ?? null,
                'contact_name' => $companyData['contact_name'] ?? $userData['name'],
                'contact_email' => $companyData['contact_email'] ?? $userData['email'],
                'contact_phone' => $companyData['contact_phone'] ?? ($userData['phone'] ?? null),
                'status' => 'pending',
                'is_verified' => false,
            ]);

            $company->members()->create([
                'user_id' => $user->id,
                'role' => CompanyMemberRole::Owner->value,
                'is_primary_contact' => true,
                'invited_at' => null,
                'accepted_at' => now(),
            ]);

            Event::dispatch(new Registered($user));

            return ['user' => $user, 'company' => $company];
        });

        $this->notifyAdminsOfPendingRegistration(
            user: $result['user'],
            roleLabel: 'Usuario de empresa',
            companyName: $result['company']->legal_name,
            reason: $companyData['motivo'] ?? null,
        );

        return $result['user'];
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
