<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\CandidateProfile;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function authCandidate(): User
{
    $user = User::factory()->create(['name' => 'Ana Pérez']);
    $user->assignRole(UserRole::Candidate->value);
    Sanctum::actingAs($user);

    return $user;
}

it('auto-creates an empty profile on first GET /me/profile', function (): void {
    $user = authCandidate();

    $response = $this->getJson('/api/v1/me/profile');

    $response
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.user_id', $user->id)
        ->assertJsonPath('data.first_name', 'Ana')
        ->assertJsonPath('data.last_name', 'Pérez');

    expect(CandidateProfile::where('user_id', $user->id)->count())->toBe(1);
});

it('updates profile fields via PATCH', function (): void {
    authCandidate();

    $response = $this->patchJson('/api/v1/me/profile', [
        'headline' => 'UX Designer con 5 años',
        'summary' => 'Diseñadora apasionada por accesibilidad.',
        'years_of_experience' => 5,
        'open_to_remote' => true,
        'availability' => 'inmediata',
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('data.headline', 'UX Designer con 5 años')
        ->assertJsonPath('data.years_of_experience', 5)
        ->assertJsonPath('data.open_to_remote', true);
});

it('rejects update with invalid data', function (): void {
    authCandidate();

    $response = $this->patchJson('/api/v1/me/profile', [
        'years_of_experience' => 200,
        'expected_salary_min' => -5,
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['years_of_experience', 'expected_salary_min']);
});

it('rejects unauthenticated profile access', function (): void {
    $this->getJson('/api/v1/me/profile')->assertStatus(401);
});
