<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

// F-17: this used to assert `data.token` came back. A token the auth system
// refuses on every other path is not a session, it is a hole — `login` rejects
// the same account with 403 `email_unverified`. Registration now returns the
// created user and a flag telling the client to go read its mail, matching the
// order ARCHITECTURE.md §8.1 sets out. The gate itself lives in
// VerifiedEmailGateTest.
it('registers a new candidate without issuing a session before verification', function (): void {
    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'Nuevo Candidato',
        'email' => 'nuevo@humae.com.mx',
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
        'accept_terms' => true,
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.user.email', 'nuevo@humae.com.mx')
        ->assertJsonPath('data.verification_required', true)
        ->assertJsonMissingPath('data.token')
        ->assertJsonMissingPath('data.token_type')
        ->assertJsonStructure([
            'success',
            'message',
            'data' => ['user' => ['id', 'email', 'roles', 'permissions'], 'verification_required'],
        ]);

    $user = User::where('email', 'nuevo@humae.com.mx')->first();

    expect($user)->not->toBeNull()
        ->and($user->hasRole(UserRole::Candidate->value))->toBeTrue()
        ->and($user->email_verified_at)->toBeNull()
        ->and($user->tokens()->count())->toBe(0);
});

it('rejects registration with existing email', function (): void {
    User::factory()->create(['email' => 'ya@humae.com.mx']);

    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'Dup',
        'email' => 'ya@humae.com.mx',
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
        'accept_terms' => true,
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('email');
});

it('rejects weak passwords', function (): void {
    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'Weak',
        'email' => 'weak@humae.com.mx',
        'password' => 'abc',
        'password_confirmation' => 'abc',
        'accept_terms' => true,
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('password');
});

it('requires accept_terms checkbox', function (): void {
    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'Sin Terms',
        'email' => 'sint@humae.com.mx',
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
        'accept_terms' => false,
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('accept_terms');
});
