<?php

declare(strict_types=1);

use App\Enums\AssignmentStage;
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

it('creates a vacancy in activa state and assigns candidate in one shot', function (): void {
    [$owner, $company] = makeOwnerWithCompanyCwc();
    $candidate = makeActiveCandidateCwc();
    Sanctum::actingAs($owner);

    $response = $this->postJson('/api/v1/me/company/vacancies', [
        'company_id' => $company->id,
        'title' => 'Senior Backend Engineer',
        'description' => 'Buscamos backend con Laravel',
        'auto_assign_candidate_profile_id' => $candidate->id,
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.state', VacancyState::Activa->value);

    $vacancyId = $response->json('data.id');

    $assignment = VacancyAssignment::where('vacancy_id', $vacancyId)
        ->where('candidate_profile_id', $candidate->id)
        ->first();

    expect($assignment)->not->toBeNull();
    expect($assignment->stage)->toBe(AssignmentStage::Sourced);
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

it('rolls back the vacancy if the candidate has no active membership', function (): void {
    [$owner, $company] = makeOwnerWithCompanyCwc();
    $candidateUser = User::factory()->create();
    $candidateUser->assignRole(UserRole::Candidate->value);
    $profile = CandidateProfile::factory()->create([
        'user_id' => $candidateUser->id,
        'state' => CandidateState::Activo->value,
    ]);
    // No membership at all → assign fails → entire transaction rolls back.
    Sanctum::actingAs($owner);

    $before = Vacancy::count();

    $this->postJson('/api/v1/me/company/vacancies', [
        'company_id' => $company->id,
        'title' => 'Con candidato sin membresía',
        'description' => 'No debería crear nada',
        'auto_assign_candidate_profile_id' => $profile->id,
    ])->assertStatus(409);

    expect(Vacancy::count())->toBe($before);
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
