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
use App\Models\MembershipPlan;
use App\Models\SalaryCurrency;
use App\Models\User;
use App\Models\Vacancy;
use App\Models\VacancyAssignment;
use App\Models\VacancyAssignmentNote;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function pipelineMakeCandidateWithMembership(): CandidateProfile
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

    return CandidateProfile::factory()->create([
        'user_id' => $user->id,
        'state' => CandidateState::Activo->value,
    ]);
}

function pipelineMakeVacancy(VacancyState $state = VacancyState::EnBusqueda): Vacancy
{
    $company = Company::factory()->create();

    return Vacancy::factory()->create([
        'company_id' => $company->id,
        'state' => $state,
    ]);
}

function actAsRecruiterP(): User
{
    $user = User::factory()->create();
    $user->assignRole(UserRole::Recruiter->value);
    Sanctum::actingAs($user);

    return $user;
}

it('recruiter assigns a candidate to a vacancy', function (): void {
    $recruiter = actAsRecruiterP();
    $vacancy = pipelineMakeVacancy();
    $candidate = pipelineMakeCandidateWithMembership();

    $response = $this->postJson("/api/v1/vacancies/{$vacancy->id}/assignments", [
        'candidate_profile_id' => $candidate->id,
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.stage', 'sourced')
        ->assertJsonPath('data.candidate_profile_id', $candidate->id);

    // La vacancy pasa de en_busqueda → con_candidatos_asignados
    expect($vacancy->fresh()->state->value)->toBe('con_candidatos_asignados');
});

it('rejects duplicate assignments', function (): void {
    actAsRecruiterP();
    $vacancy = pipelineMakeVacancy();
    $candidate = pipelineMakeCandidateWithMembership();

    $this->postJson("/api/v1/vacancies/{$vacancy->id}/assignments", [
        'candidate_profile_id' => $candidate->id,
    ])->assertCreated();

    $second = $this->postJson("/api/v1/vacancies/{$vacancy->id}/assignments", [
        'candidate_profile_id' => $candidate->id,
    ]);
    $second->assertStatus(409);
});

it('rejects assignment when candidate has no active membership', function (): void {
    actAsRecruiterP();
    $vacancy = pipelineMakeVacancy();

    $noMembershipUser = User::factory()->create();
    $noMembershipUser->assignRole(UserRole::Candidate->value);
    $candidate = CandidateProfile::factory()->create(['user_id' => $noMembershipUser->id]);

    $response = $this->postJson("/api/v1/vacancies/{$vacancy->id}/assignments", [
        'candidate_profile_id' => $candidate->id,
    ]);

    $response->assertStatus(409);
});

it('rejects assignment when vacancy is in terminal state', function (): void {
    actAsRecruiterP();
    $vacancy = pipelineMakeVacancy(VacancyState::Cancelada);
    $candidate = pipelineMakeCandidateWithMembership();

    $response = $this->postJson("/api/v1/vacancies/{$vacancy->id}/assignments", [
        'candidate_profile_id' => $candidate->id,
    ]);

    $response->assertStatus(409);
});

it('transitions stage and stamps timestamp', function (): void {
    actAsRecruiterP();
    $vacancy = pipelineMakeVacancy();
    $candidate = pipelineMakeCandidateWithMembership();
    $assignment = VacancyAssignment::factory()->create([
        'vacancy_id' => $vacancy->id,
        'candidate_profile_id' => $candidate->id,
        'stage' => AssignmentStage::Sourced,
    ]);

    $response = $this->patchJson("/api/v1/assignments/{$assignment->id}", [
        'stage' => 'presented',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.stage', 'presented');

    expect($assignment->fresh()->presented_at)->not->toBeNull();
});

it('rejects invalid stage transitions', function (): void {
    actAsRecruiterP();
    $vacancy = pipelineMakeVacancy();
    $candidate = pipelineMakeCandidateWithMembership();
    $assignment = VacancyAssignment::factory()->create([
        'vacancy_id' => $vacancy->id,
        'candidate_profile_id' => $candidate->id,
        'stage' => AssignmentStage::Sourced,
    ]);

    $response = $this->patchJson("/api/v1/assignments/{$assignment->id}", [
        'stage' => 'hired',
    ]);

    $response->assertStatus(409);
});

it('company_user can select finalist on own vacancy', function (): void {
    $companyUser = User::factory()->create();
    $companyUser->assignRole(UserRole::CompanyUser->value);

    $company = Company::factory()->create();
    CompanyMember::create([
        'company_id' => $company->id,
        'user_id' => $companyUser->id,
        'role' => CompanyMemberRole::Owner->value,
    ]);

    $vacancy = Vacancy::factory()->create([
        'company_id' => $company->id,
        'state' => VacancyState::EntrevistasEnCurso,
    ]);

    $candidate = pipelineMakeCandidateWithMembership();
    $assignment = VacancyAssignment::factory()->create([
        'vacancy_id' => $vacancy->id,
        'candidate_profile_id' => $candidate->id,
        'stage' => AssignmentStage::Interviewing,
    ]);

    Sanctum::actingAs($companyUser);
    $response = $this->patchJson("/api/v1/assignments/{$assignment->id}/select-finalist");

    $response->assertOk()
        ->assertJsonPath('data.stage', 'finalist');
});

it('company_user cannot select finalist on other company vacancy', function (): void {
    $companyUser = User::factory()->create();
    $companyUser->assignRole(UserRole::CompanyUser->value);
    Sanctum::actingAs($companyUser);

    $otherVacancy = pipelineMakeVacancy(VacancyState::EntrevistasEnCurso);
    $candidate = pipelineMakeCandidateWithMembership();
    $assignment = VacancyAssignment::factory()->create([
        'vacancy_id' => $otherVacancy->id,
        'candidate_profile_id' => $candidate->id,
        'stage' => AssignmentStage::Interviewing,
    ]);

    $response = $this->patchJson("/api/v1/assignments/{$assignment->id}/select-finalist");
    $response->assertStatus(403);
});

it('recruiter creates an internal note', function (): void {
    $recruiter = actAsRecruiterP();
    $vacancy = pipelineMakeVacancy();
    $candidate = pipelineMakeCandidateWithMembership();
    $assignment = VacancyAssignment::factory()->create([
        'vacancy_id' => $vacancy->id,
        'candidate_profile_id' => $candidate->id,
    ]);

    $response = $this->postJson("/api/v1/assignments/{$assignment->id}/notes", [
        'body' => 'Excelente perfil técnico. Recomiendo avanzar.',
        'visibility' => 'internal',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.visibility', 'internal')
        ->assertJsonPath('data.author.id', $recruiter->id);
});

it('company_user sees only company-visible notes', function (): void {
    $companyUser = User::factory()->create();
    $companyUser->assignRole(UserRole::CompanyUser->value);

    $company = Company::factory()->create();
    CompanyMember::create([
        'company_id' => $company->id,
        'user_id' => $companyUser->id,
        'role' => CompanyMemberRole::Owner->value,
    ]);

    $vacancy = Vacancy::factory()->create([
        'company_id' => $company->id,
        'state' => VacancyState::EnBusqueda,
    ]);
    $candidate = pipelineMakeCandidateWithMembership();
    $assignment = VacancyAssignment::factory()->create([
        'vacancy_id' => $vacancy->id,
        'candidate_profile_id' => $candidate->id,
    ]);

    $recruiter = User::factory()->create();
    $recruiter->assignRole(UserRole::Recruiter->value);

    VacancyAssignmentNote::create([
        'vacancy_assignment_id' => $assignment->id,
        'author_id' => $recruiter->id,
        'visibility' => 'internal',
        'body' => 'Nota privada de HUMAE',
    ]);
    VacancyAssignmentNote::create([
        'vacancy_assignment_id' => $assignment->id,
        'author_id' => $recruiter->id,
        'visibility' => 'company',
        'body' => 'Nota visible por la empresa',
    ]);

    Sanctum::actingAs($companyUser);
    $response = $this->getJson("/api/v1/assignments/{$assignment->id}/notes");

    $response->assertOk();
    $bodies = collect($response->json('data'))->pluck('body')->all();
    expect($bodies)->toContain('Nota visible por la empresa')
        ->and($bodies)->not->toContain('Nota privada de HUMAE');
});

it('rejects unauthenticated pipeline access', function (): void {
    $vacancy = pipelineMakeVacancy();
    $this->getJson("/api/v1/vacancies/{$vacancy->id}/assignments")->assertStatus(401);
});
