<?php

declare(strict_types=1);

use App\Enums\CompanyMemberRole;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\User;
use App\Notifications\PendingUserRegistrationNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $admin = User::factory()->create([
        'email' => 'admin-test@humae.test',
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $admin->assignRole(UserRole::Admin->value);
});

it('registers a company_user with company + owner pivot pending', function (): void {
    Notification::fake();

    $response = $this->postJson('/api/v1/auth/register/company', [
        'name' => 'Owner Empresa',
        'email' => 'owner@empresa.test',
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
        'accept_terms' => true,
        'company' => [
            'legal_name' => 'Empresa Demo S.A. de C.V.',
            'trade_name' => 'Empresa Demo',
            'website' => 'https://empresa.test',
            'contact_phone' => '+52 55 1234 5678',
            'motivo' => 'Buscamos contratar talento HUMAE.',
        ],
    ]);

    $response->assertCreated()->assertJsonPath('data.pending_approval', true);

    $user = User::where('email', 'owner@empresa.test')->firstOrFail();
    expect($user->status)->toBe(UserStatus::PendingApproval->value)
        ->and($user->hasRole(UserRole::CompanyUser->value))->toBeTrue();

    $company = Company::where('legal_name', 'Empresa Demo S.A. de C.V.')->firstOrFail();
    expect($company->status)->toBe('pending')
        ->and($company->is_verified)->toBeFalse();

    $member = CompanyMember::where('company_id', $company->id)
        ->where('user_id', $user->id)
        ->firstOrFail();

    $memberRole = $member->role instanceof CompanyMemberRole
        ? $member->role->value
        : (string) $member->role;
    expect($memberRole)->toBe(CompanyMemberRole::Owner->value)
        ->and($member->is_primary_contact)->toBeTrue();

    $admin = User::where('email', 'admin-test@humae.test')->firstOrFail();
    Notification::assertSentTo($admin, PendingUserRegistrationNotification::class);
});

it('requires company.legal_name when registering as company', function (): void {
    $response = $this->postJson('/api/v1/auth/register/company', [
        'name' => 'Owner',
        'email' => 'owner2@empresa.test',
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
        'accept_terms' => true,
        'company' => [],
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['company.legal_name']);
});
