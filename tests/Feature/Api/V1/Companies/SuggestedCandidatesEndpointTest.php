<?php

declare(strict_types=1);

use App\Enums\CandidateKind;
use App\Enums\CandidateState;
use App\Enums\CompanyMemberRole;
use App\Enums\MembershipStatus;
use App\Enums\UserRole;
use App\Enums\VacancyTargetKind;
use App\Models\CandidateProfile;
use App\Models\Company;
use App\Models\CompanyMember;
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

    // A candidate belongs to no client company, so the tenancy scope leaves it
    // with an empty set and the vacancy never resolves.
    $this->getJson("/api/v1/vacancies/{$vacancy->id}/suggested-candidates")
        ->assertNotFound();
});

/**
 * The matching engine ranks the whole talent base and returns names, headlines,
 * seniority and functional areas for candidates HUMAE never presented. That is
 * the directory reached through a different door, and ARCHITECTURE.md §6 closes
 * the directory to the client company. Owning the vacancy grants the pipeline
 * ("Ver candidatos asignados a vacante — Empresa: ✅ propia vacante"), not the
 * sourcing pool.
 */
it('blocks the owning company from the matching engine — sourcing is HUMAE curation', function (): void {
    $companyUser = User::factory()->create();
    $companyUser->assignRole(UserRole::CompanyUser->value);

    $company = Company::factory()->create();
    CompanyMember::create([
        'company_id' => $company->id,
        'user_id' => $companyUser->id,
        'role' => CompanyMemberRole::Owner->value,
        'is_primary_contact' => true,
        'accepted_at' => now(),
    ]);

    $vacancy = Vacancy::factory()->create(['company_id' => $company->id]);
    makeActiveCandidate(['candidate_kind' => CandidateKind::Employee]);

    Sanctum::actingAs($companyUser);

    $this->getJson("/api/v1/vacancies/{$vacancy->id}/suggested-candidates")
        ->assertStatus(403);
});

it('keeps the matching engine open to an admin', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole(UserRole::Admin->value);
    Sanctum::actingAs($admin);

    $company = Company::factory()->create();
    $vacancy = Vacancy::factory()->create(['company_id' => $company->id]);
    makeActiveCandidate(['candidate_kind' => CandidateKind::Employee]);

    $this->getJson("/api/v1/vacancies/{$vacancy->id}/suggested-candidates")
        ->assertOk();
});
