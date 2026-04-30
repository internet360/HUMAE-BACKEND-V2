<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('blocks login when email is not verified', function (): void {
    $user = User::factory()->create([
        'email' => 'unverified@humae.test',
        'email_verified_at' => null,
        'status' => UserStatus::Active->value,
        'password' => bcrypt('Password123'),
    ]);
    $user->assignRole(UserRole::Recruiter->value);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'unverified@humae.test',
        'password' => 'Password123',
    ]);

    $response
        ->assertStatus(403)
        ->assertJsonPath('errors.code', ['email_unverified']);
});

it('blocks login when status is pending_approval', function (): void {
    $user = User::factory()->create([
        'email' => 'pending@humae.test',
        'email_verified_at' => now(),
        'status' => UserStatus::PendingApproval->value,
        'password' => bcrypt('Password123'),
    ]);
    $user->assignRole(UserRole::Recruiter->value);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'pending@humae.test',
        'password' => 'Password123',
    ]);

    $response
        ->assertStatus(403)
        ->assertJsonPath('errors.code', ['pending_approval']);
});

it('blocks login when status is inactive', function (): void {
    $user = User::factory()->create([
        'email' => 'inactive@humae.test',
        'email_verified_at' => now(),
        'status' => UserStatus::Inactive->value,
        'password' => bcrypt('Password123'),
    ]);
    $user->assignRole(UserRole::Recruiter->value);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'inactive@humae.test',
        'password' => 'Password123',
    ]);

    $response
        ->assertStatus(403)
        ->assertJsonPath('errors.code', ['account_inactive']);
});

it('logs in when email is verified and status is active', function (): void {
    $user = User::factory()->create([
        'email' => 'ok@humae.test',
        'email_verified_at' => now(),
        'status' => UserStatus::Active->value,
        'password' => bcrypt('Password123'),
    ]);
    $user->assignRole(UserRole::Recruiter->value);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'ok@humae.test',
        'password' => 'Password123',
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('data.user.email', 'ok@humae.test')
        ->assertJsonStructure(['data' => ['token']]);
});
