<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('returns the authenticated user on /me', function (): void {
    $user = User::factory()->create(['email' => 'me@humae.com.mx']);
    $user->assignRole(UserRole::Candidate->value);

    Sanctum::actingAs($user);

    $response = $this->getJson('/api/v1/auth/me');

    $response
        ->assertOk()
        ->assertJsonPath('data.email', 'me@humae.com.mx')
        ->assertJsonPath('data.roles.0', 'candidate');
});

it('rejects /me without authentication', function (): void {
    $response = $this->getJson('/api/v1/auth/me');

    $response->assertStatus(401);
});

it('revokes current token on logout', function (): void {
    $user = User::factory()->create();
    $user->assignRole(UserRole::Candidate->value);

    Sanctum::actingAs($user);

    $response = $this->postJson('/api/v1/auth/logout');

    $response->assertStatus(204);
});
