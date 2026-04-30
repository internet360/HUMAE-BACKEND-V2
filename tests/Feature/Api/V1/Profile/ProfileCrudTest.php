<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\CandidateProfile;
use App\Models\FunctionalArea;
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

it('persists candidate_kind via PATCH /me/profile', function (): void {
    authCandidate();

    $this->patchJson('/api/v1/me/profile', [
        'candidate_kind' => 'intern',
    ])
        ->assertOk()
        ->assertJsonPath('data.candidate_kind', 'intern');
});

it('rejects invalid candidate_kind value', function (): void {
    authCandidate();

    $this->patchJson('/api/v1/me/profile', [
        'candidate_kind' => 'banana',
    ])->assertStatus(422);
});

it('syncs multiple functional areas with one marked as primary', function (): void {
    authCandidate();

    $a1 = FunctionalArea::factory()->create(['name' => 'Producción']);
    $a2 = FunctionalArea::factory()->create(['name' => 'Calidad']);
    $a3 = FunctionalArea::factory()->create(['name' => 'Mantenimiento']);

    $response = $this->patchJson('/api/v1/me/profile', [
        'functional_areas' => [
            ['id' => $a1->id, 'is_primary' => false],
            ['id' => $a2->id, 'is_primary' => true],
            ['id' => $a3->id, 'is_primary' => false],
        ],
    ])->assertOk();

    $areas = collect($response->json('data.functional_areas'));
    expect($areas)->toHaveCount(3);

    $primary = $areas->firstWhere('is_primary', true);
    expect($primary['id'])->toBe($a2->id);

    // El campo legacy single debe quedar apuntando a la primaria.
    $this->assertSame($a2->id, CandidateProfile::first()->functional_area_id);
});

it('rejects more than 10 functional areas', function (): void {
    authCandidate();
    $areas = FunctionalArea::factory()->count(11)->create();

    $payload = $areas->map(fn ($a) => ['id' => $a->id])->all();

    $this->patchJson('/api/v1/me/profile', [
        'functional_areas' => $payload,
    ])->assertStatus(422);
});

it('rejects functional area with non-existing id', function (): void {
    authCandidate();

    $this->patchJson('/api/v1/me/profile', [
        'functional_areas' => [['id' => 99999]],
    ])->assertStatus(422);
});

it('persists other_area_text on profile', function (): void {
    authCandidate();

    $this->patchJson('/api/v1/me/profile', [
        'other_area_text' => 'Bioingeniería',
    ])->assertOk()
        ->assertJsonPath('data.other_area_text', 'Bioingeniería');
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
