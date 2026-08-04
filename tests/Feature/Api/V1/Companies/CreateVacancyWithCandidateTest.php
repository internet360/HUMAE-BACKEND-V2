<?php

declare(strict_types=1);

use App\Enums\CandidateState;
use App\Enums\CompanyMemberRole;
use App\Enums\MembershipStatus;
use App\Enums\UserRole;
use App\Enums\VacancyState;
use App\Models\CandidateProfile;
use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\Membership;
use App\Models\User;
use App\Models\Vacancy;
use App\Models\VacancyAssignment;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function makeOwnerWithCompanyCwc(): array
{
    $user = User::factory()->create();
    $user->assignRole(UserRole::CompanyUser->value);
    $company = Company::factory()->create();
    CompanyMember::create([
        'company_id' => $company->id,
        'user_id' => $user->id,
        'role' => CompanyMemberRole::Owner->value,
    ]);

    return [$user, $company];
}

function makeActiveCandidateCwc(): CandidateProfile
{
    $candidateUser = User::factory()->create();
    $candidateUser->assignRole(UserRole::Candidate->value);
    $profile = CandidateProfile::factory()->create([
        'user_id' => $candidateUser->id,
        'state' => CandidateState::Activo->value,
    ]);
    Membership::factory()->create([
        'user_id' => $candidateUser->id,
        'status' => MembershipStatus::Active->value,
        'expires_at' => now()->addMonths(3),
    ]);

    return $profile;
}

/**
 * ARCHITECTURE.md §6: "Asignar candidatos a vacante — Empresa cliente: ❌".
 * The endpoint used to accept `auto_assign_candidate_profile_id`, which let a
 * company attach a candidate of her choosing to her own vacancy at creation
 * time and auto-published the vacancy. That is HUMAE's curation step (§1), so
 * the parameter is gone: the payload is ignored and the vacancy still lands in
 * `borrador` for HUMAE to review.
 */
it('does not let a company assign a candidate while creating a vacancy', function (): void {
    [$owner, $company] = makeOwnerWithCompanyCwc();
    $candidate = makeActiveCandidateCwc();
    Sanctum::actingAs($owner);

    $response = $this->postJson('/api/v1/me/company/vacancies', [
        'company_id' => $company->id,
        'title' => 'Senior Backend Engineer',
        'description' => 'Buscamos backend con Laravel',
        'auto_assign_candidate_profile_id' => $candidate->id,
    ]);

    // The vacancy is still created — it is a legitimate request — but as a
    // draft, and with nobody in the pipeline.
    $response->assertCreated()
        ->assertJsonPath('data.state', VacancyState::Borrador->value);

    expect(VacancyAssignment::where('candidate_profile_id', $candidate->id)->exists())
        ->toBeFalse();
    expect(VacancyAssignment::count())->toBe(0);
});

it('keeps the pipeline assignment endpoint closed to a company', function (): void {
    [$owner, $company] = makeOwnerWithCompanyCwc();
    $candidate = makeActiveCandidateCwc();
    $vacancy = Vacancy::factory()->create([
        'company_id' => $company->id,
        'state' => VacancyState::Activa->value,
    ]);
    Sanctum::actingAs($owner);

    // Her own vacancy, her own company: still forbidden. Assigning is HUMAE's.
    $this->postJson("/api/v1/vacancies/{$vacancy->id}/assignments", [
        'candidate_profile_id' => $candidate->id,
    ])->assertForbidden();

    expect(VacancyAssignment::count())->toBe(0);
});

it('creates a vacancy in borrador when no auto-assign candidate is provided', function (): void {
    [$owner, $company] = makeOwnerWithCompanyCwc();
    Sanctum::actingAs($owner);

    $response = $this->postJson('/api/v1/me/company/vacancies', [
        'company_id' => $company->id,
        'title' => 'Otra vacante',
        'description' => 'Sin candidato',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.state', VacancyState::Borrador->value);

    expect(VacancyAssignment::count())->toBe(0);
});

it('discards assigned_recruiter_id sent by company_user', function (): void {
    [$owner, $company] = makeOwnerWithCompanyCwc();
    $recruiter = User::factory()->create();
    $recruiter->assignRole(UserRole::Recruiter->value);
    Sanctum::actingAs($owner);

    $response = $this->postJson('/api/v1/me/company/vacancies', [
        'company_id' => $company->id,
        'title' => 'Empresa intenta asignar recruiter',
        'description' => 'No debería persistirse',
        'assigned_recruiter_id' => $recruiter->id,
    ])->assertCreated();

    $vacancy = Vacancy::find($response->json('data.id'));
    expect($vacancy->assigned_recruiter_id)->toBeNull();
});
