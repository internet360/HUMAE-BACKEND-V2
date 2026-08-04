<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\CandidateProfile;
use App\Models\FunctionalArea;
use App\Models\User;
use App\Services\ProfileService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
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

/*
|--------------------------------------------------------------------------
| The candidate surface belongs to candidates (F-09)
|--------------------------------------------------------------------------
|
| §5.2 and §5.4 head both sections "auth, role: candidate" and none of the 30
| routes checked it. The access hole came with a data-integrity one:
| `ProfileService::findOrCreate` minted a `candidate_profiles` row for whoever
| called, so a recruiter who merely opened `GET /me/profile` enrolled himself in
| the directory HUMAE sells — and it fired even on the paths that answer 404,
| because ownership was resolved through the creating call.
|
*/

it('keeps every non-candidate role out of the self-service surface', function (): void {
    foreach ([UserRole::Recruiter, UserRole::CompanyUser, UserRole::Admin] as $role) {
        $user = User::factory()->create();
        $user->assignRole($role->value);
        Sanctum::actingAs($user);

        foreach ([
            '/api/v1/me/profile',
            '/api/v1/me/profile/experiences',
            '/api/v1/me/profile/documents',
            '/api/v1/me/profile/cv.pdf',
            '/api/v1/me/psychometrics/tests',
        ] as $uri) {
            $this->getJson($uri)->assertForbidden();
        }

        expect(CandidateProfile::where('user_id', $user->id)->exists())
            ->toBeFalse("{$role->value} was enrolled in the candidate directory");
    }
});

it('does not mint a profile on a request that is about to be refused', function (): void {
    $owner = authCandidate();
    $intruder = User::factory()->create();
    $intruder->assignRole(UserRole::Candidate->value);

    $experience = $this->postJson('/api/v1/me/profile/experiences', [
        'company_name' => 'ACME',
        'position_title' => 'Dev',
        'start_date' => '2020-01-01',
        'is_current' => true,
    ])->assertCreated()->json('data.id');

    Sanctum::actingAs($intruder);

    // `ensureOwned()` resolves without creating now, so the 404 is a pure read.
    $before = CandidateProfile::count();

    $this->patchJson("/api/v1/me/profile/experiences/{$experience}", [
        'company_name' => 'Reescrito',
        'position_title' => 'Dev',
        'start_date' => '2020-01-01',
    ])->assertNotFound();

    expect(CandidateProfile::count())->toBe($before)
        ->and(CandidateProfile::where('user_id', $intruder->id)->exists())->toBeFalse()
        ->and(CandidateProfile::where('user_id', $owner->id)->exists())->toBeTrue();
});

it('refuses to mint a directory record for a non-candidate at the service level', function (): void {
    // Defence in depth: the route gate is the wall, this is the rule being true
    // of the service itself so the next caller cannot reintroduce F-09.
    $recruiter = User::factory()->create();
    $recruiter->assignRole(UserRole::Recruiter->value);

    expect(fn () => app(ProfileService::class)->findOrCreate($recruiter))
        ->toThrow(AuthorizationException::class)
        ->and(app(ProfileService::class)->find($recruiter))->toBeNull()
        ->and(CandidateProfile::where('user_id', $recruiter->id)->exists())->toBeFalse();
});
