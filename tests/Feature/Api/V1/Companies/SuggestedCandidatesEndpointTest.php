<?php

declare(strict_types=1);

use App\Enums\CandidateKind;
use App\Enums\CandidateState;
use App\Enums\MembershipStatus;
use App\Enums\UserRole;
use App\Enums\VacancyTargetKind;
use App\Models\CandidateProfile;
use App\Models\Company;
use App\Models\FunctionalArea;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\SalaryCurrency;
use App\Models\User;
use App\Models\Vacancy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $mxn = SalaryCurrency::factory()->create(['code' => 'MXN']);
    $this->plan = MembershipPlan::factory()->create([
        'salary_currency_id' => $mxn->id,
        'duration_days' => 180,
    ]);
});

function makeActiveCandidate(array $attrs = []): CandidateProfile
{
    $user = User::factory()->create();
    Membership::factory()->create([
        'user_id' => $user->id,
        'membership_plan_id' => test()->plan->id,
        'status' => MembershipStatus::Active,
        'expires_at' => now()->addDays(30),
    ]);

    return CandidateProfile::factory()->create(array_merge([
        'user_id' => $user->id,
        'state' => CandidateState::Activo,
    ], $attrs));
}

it('recruiter can fetch suggested candidates ordered by score', function (): void {
    $recruiter = User::factory()->create();
    $recruiter->assignRole(UserRole::Recruiter->value);
    Sanctum::actingAs($recruiter);

    $area = FunctionalArea::factory()->create();
    $company = Company::factory()->create();
    $vacancy = Vacancy::factory()->create([
        'company_id' => $company->id,
        'target_candidate_kind' => VacancyTargetKind::Intern,
        'functional_area_id' => $area->id,
    ]);

    // Match perfecto: intern + área principal
    $best = makeActiveCandidate([
        'candidate_kind' => CandidateKind::Intern,
    ]);
    $best->functionalAreas()->attach($area->id, ['is_primary' => true, 'sort_order' => 0]);

    // Empleado sin área: kind 0, areas 0
    makeActiveCandidate(['candidate_kind' => CandidateKind::Employee]);

    $response = $this->getJson("/api/v1/vacancies/{$vacancy->id}/suggested-candidates")
        ->assertOk();

    $items = $response->json('data');
    expect($items)->toHaveCount(2)
        ->and($items[0]['candidate']['id'])->toBe($best->id)
        ->and($items[0]['score'])->toBeGreaterThan($items[1]['score'])
        ->and($items[0]['breakdown'])->toHaveKeys([
            'kind', 'areas', 'education', 'experience', 'skills', 'salary',
        ]);
});

it('respects min_score query param', function (): void {
    $recruiter = User::factory()->create();
    $recruiter->assignRole(UserRole::Recruiter->value);
    Sanctum::actingAs($recruiter);

    $company = Company::factory()->create();
    $vacancy = Vacancy::factory()->create([
        'company_id' => $company->id,
        'target_candidate_kind' => VacancyTargetKind::Intern,
    ]);

    makeActiveCandidate(['candidate_kind' => CandidateKind::Intern]);
    makeActiveCandidate(['candidate_kind' => CandidateKind::Employee]);

    $response = $this->getJson("/api/v1/vacancies/{$vacancy->id}/suggested-candidates?min_score=70")
        ->assertOk();

    expect($response->json('data'))->toHaveCount(1);
});

it('candidate role cannot access suggested candidates', function (): void {
    $candidateUser = User::factory()->create();
    $candidateUser->assignRole(UserRole::Candidate->value);
    Sanctum::actingAs($candidateUser);

    $company = Company::factory()->create();
    $vacancy = Vacancy::factory()->create(['company_id' => $company->id]);

    $this->getJson("/api/v1/vacancies/{$vacancy->id}/suggested-candidates")
        ->assertStatus(403);
});
