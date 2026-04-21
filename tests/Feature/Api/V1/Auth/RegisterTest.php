<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('registers a new candidate and returns token + user envelope', function (): void {
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
        ->assertJsonStructure([
            'success',
            'message',
            'data' => ['user' => ['id', 'email', 'roles', 'permissions'], 'token', 'token_type'],
        ]);

    $user = User::where('email', 'nuevo@humae.com.mx')->first();

    expect($user)->not->toBeNull()
        ->and($user->hasRole(UserRole::Candidate->value))->toBeTrue();
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
