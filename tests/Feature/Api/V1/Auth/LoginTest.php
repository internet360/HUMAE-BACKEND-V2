<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Hash;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('logs in with valid credentials', function (): void {
    $user = User::factory()->create([
        'email' => 'login@humae.com.mx',
        'password' => Hash::make('Password123'),
        'status' => 'active',
    ]);
    $user->assignRole(UserRole::Candidate->value);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'login@humae.com.mx',
        'password' => 'Password123',
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.user.email', 'login@humae.com.mx')
        ->assertJsonStructure(['data' => ['user', 'token', 'token_type']]);
});

it('rejects invalid password', function (): void {
    User::factory()->create([
        'email' => 'bad@humae.com.mx',
        'password' => Hash::make('Password123'),
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'bad@humae.com.mx',
        'password' => 'wrong',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('email');
});

it('rejects login for inactive user', function (): void {
    User::factory()->create([
        'email' => 'inactive@humae.com.mx',
        'password' => Hash::make('Password123'),
        'status' => 'suspended',
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'inactive@humae.com.mx',
        'password' => 'Password123',
    ]);

    $response->assertStatus(403)
        ->assertJsonPath('success', false);
});
