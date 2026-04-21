<?php

declare(strict_types=1);

use App\Enums\CandidateState;
use App\Enums\MembershipStatus;
use App\Enums\UserRole;
use App\Models\CandidateProfile;
use App\Models\DirectoryFavorite;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\SalaryCurrency;
use App\Models\Skill;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function makeCandidateWithActiveMembership(array $profile = []): CandidateProfile
{
    $user = User::factory()->create();
    $user->assignRole(UserRole::Candidate->value);

    $currency = SalaryCurrency::where('code', 'MXN')->first()
        ?? SalaryCurrency::factory()->create(['code' => 'MXN']);

    $plan = MembershipPlan::where('code', 'candidate_6m')->first()
        ?? MembershipPlan::factory()->create([
            'code' => 'candidate_6m',
            'salary_currency_id' => $currency->id,
        ]);

    Membership::factory()->create([
        'user_id' => $user->id,
        'membership_plan_id' => $plan->id,
        'status' => MembershipStatus::Active,
        'started_at' => now()->subDay(),
        'expires_at' => now()->addDays(100),
    ]);

    return CandidateProfile::factory()->create(array_merge([
        'user_id' => $user->id,
        'state' => CandidateState::Activo->value,
    ], $profile));
}

function actAsRecruiter(): User
{
    $user = User::factory()->create();
    $user->assignRole(UserRole::Recruiter->value);
    Sanctum::actingAs($user);

    return $user;
}

it('lists only candidates with active membership by default', function (): void {
    $active = makeCandidateWithActiveMembership(['headline' => 'Con membresía']);

    // Candidato sin membresía activa
    $userInactive = User::factory()->create();
    $userInactive->assignRole(UserRole::Candidate->value);
    CandidateProfile::factory()->create([
        'user_id' => $userInactive->id,
        'state' => CandidateState::Activo->value,
        'headline' => 'Sin membresía',
    ]);

    actAsRecruiter();

    $response = $this->getJson('/api/v1/directory/candidates');

    $response->assertOk();

    $headlines = collect($response->json('data'))->pluck('headline')->all();
    expect($headlines)->toContain('Con membresía')
        ->and($headlines)->not->toContain('Sin membresía');
});

it('filters by years_of_experience range', function (): void {
    makeCandidateWithActiveMembership(['years_of_experience' => 3, 'headline' => 'Junior']);
    makeCandidateWithActiveMembership(['years_of_experience' => 8, 'headline' => 'Senior']);

    actAsRecruiter();

    $response = $this->getJson('/api/v1/directory/candidates?years_exp_min=5');

    $headlines = collect($response->json('data'))->pluck('headline')->all();
    expect($headlines)->toContain('Senior')
        ->and($headlines)->not->toContain('Junior');
});

it('filters by text search', function (): void {
    makeCandidateWithActiveMembership(['headline' => 'UX Designer con Figma']);
    makeCandidateWithActiveMembership(['headline' => 'Contador Senior']);

    actAsRecruiter();

    $response = $this->getJson('/api/v1/directory/candidates?q=figma');

    $headlines = collect($response->json('data'))->pluck('headline')->all();
    expect($headlines)->toContain('UX Designer con Figma')
        ->and($headlines)->not->toContain('Contador Senior');
});

it('filters by skills (AND semantics)', function (): void {
    $skillFigma = Skill::factory()->create(['code' => 'figma', 'name' => 'Figma']);
    $skillReact = Skill::factory()->create(['code' => 'react', 'name' => 'React']);

    $both = makeCandidateWithActiveMembership(['headline' => 'Figma+React']);
    $both->skills()->attach($skillFigma->id, ['level' => 'avanzado']);
    $both->skills()->attach($skillReact->id, ['level' => 'avanzado']);

    $onlyFigma = makeCandidateWithActiveMembership(['headline' => 'Solo Figma']);
    $onlyFigma->skills()->attach($skillFigma->id, ['level' => 'avanzado']);

    actAsRecruiter();

    $response = $this->getJson(
        '/api/v1/directory/candidates?skills[]='.$skillFigma->id.'&skills[]='.$skillReact->id
    );

    $headlines = collect($response->json('data'))->pluck('headline')->all();
    expect($headlines)->toContain('Figma+React')
        ->and($headlines)->not->toContain('Solo Figma');
});

it('toggles favorite on/off per recruiter', function (): void {
    $candidate = makeCandidateWithActiveMembership();
    $recruiter = actAsRecruiter();

    $first = $this->postJson("/api/v1/directory/candidates/{$candidate->id}/favorite");
    $first->assertCreated()->assertJsonPath('data.is_favorite', true);

    expect(DirectoryFavorite::where([
        'recruiter_id' => $recruiter->id,
        'candidate_profile_id' => $candidate->id,
    ])->exists())->toBeTrue();

    $second = $this->postJson("/api/v1/directory/candidates/{$candidate->id}/favorite");
    $second->assertOk()->assertJsonPath('data.is_favorite', false);

    expect(DirectoryFavorite::where([
        'recruiter_id' => $recruiter->id,
        'candidate_profile_id' => $candidate->id,
    ])->exists())->toBeFalse();
});

it('marks is_favorite in listing payloads', function (): void {
    $candidate = makeCandidateWithActiveMembership(['headline' => 'Favorito']);
    $recruiter = actAsRecruiter();

    DirectoryFavorite::create([
        'recruiter_id' => $recruiter->id,
        'candidate_profile_id' => $candidate->id,
    ]);

    $response = $this->getJson('/api/v1/directory/candidates');

    $row = collect($response->json('data'))->firstWhere('id', $candidate->id);
    expect($row['is_favorite'])->toBeTrue();
});

it('returns candidate CV pdf', function (): void {
    $candidate = makeCandidateWithActiveMembership();
    actAsRecruiter();

    $response = $this->get("/api/v1/directory/candidates/{$candidate->id}/cv.pdf");

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
    expect(substr($response->getContent() ?: '', 0, 4))->toBe('%PDF');
});

it('blocks candidates and company_users', function (): void {
    makeCandidateWithActiveMembership();

    $candidate = User::factory()->create();
    $candidate->assignRole(UserRole::Candidate->value);
    Sanctum::actingAs($candidate);

    $this->getJson('/api/v1/directory/candidates')->assertStatus(403);

    $companyUser = User::factory()->create();
    $companyUser->assignRole(UserRole::CompanyUser->value);
    Sanctum::actingAs($companyUser);

    $this->getJson('/api/v1/directory/candidates')->assertStatus(403);
});

it('rejects unauthenticated directory access', function (): void {
    $this->getJson('/api/v1/directory/candidates')->assertStatus(401);
});
