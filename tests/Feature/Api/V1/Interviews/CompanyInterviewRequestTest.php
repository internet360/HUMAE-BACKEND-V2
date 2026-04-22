<?php

declare(strict_types=1);

use App\Enums\CandidateState;
use App\Enums\CompanyMemberRole;
use App\Enums\UserRole;
use App\Enums\VacancyState;
use App\Models\CandidateProfile;
use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\User;
use App\Models\Vacancy;
use App\Models\VacancyAssignment;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function companyOwnerWithAssignment(
    VacancyState $vacancyState = VacancyState::ConCandidatosAsignados,
): array {
    $user = User::factory()->create();
    $user->assignRole(UserRole::CompanyUser->value);
    $company = Company::factory()->create();
    CompanyMember::create([
        'company_id' => $company->id,
        'user_id' => $user->id,
        'role' => CompanyMemberRole::Owner->value,
    ]);
    $vacancy = Vacancy::factory()->create([
        'company_id' => $company->id,
        'state' => $vacancyState,
    ]);
    $candidateUser = User::factory()->create();
    $candidateUser->assignRole(UserRole::Candidate->value);
    $profile = CandidateProfile::factory()->create([
        'user_id' => $candidateUser->id,
        'state' => CandidateState::Activo->value,
    ]);
    $assignment = VacancyAssignment::factory()->create([
        'vacancy_id' => $vacancy->id,
        'candidate_profile_id' => $profile->id,
    ]);

    return compact('user', 'company', 'vacancy', 'assignment');
}

it('company_user requests an interview; interview state is propuesta', function (): void {
    ['user' => $user, 'assignment' => $assignment] = companyOwnerWithAssignment();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/v1/interviews', [
        'vacancy_assignment_id' => $assignment->id,
        'scheduled_at' => now()->addDays(2)->toIso8601String(),
        'mode' => 'online',
        'meeting_url' => 'https://meet.google.com/xyz-abcd-efg',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.state', 'propuesta');
});

it('company_user cannot request an interview for another company assignment', function (): void {
    ['user' => $userA] = companyOwnerWithAssignment();
    ['assignment' => $foreignAssignment] = companyOwnerWithAssignment();

    Sanctum::actingAs($userA);

    $this->postJson('/api/v1/interviews', [
        'vacancy_assignment_id' => $foreignAssignment->id,
        'scheduled_at' => now()->addDays(2)->toIso8601String(),
        'mode' => 'online',
        'meeting_url' => 'https://meet.google.com/xyz-abcd-efg',
    ])->assertForbidden();
});

it('company_user gets 409 if vacancy state does not accept interviews', function (): void {
    ['user' => $user, 'assignment' => $assignment] = companyOwnerWithAssignment(
        VacancyState::Borrador,
    );
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/interviews', [
        'vacancy_assignment_id' => $assignment->id,
        'scheduled_at' => now()->addDays(2)->toIso8601String(),
        'mode' => 'online',
        'meeting_url' => 'https://meet.google.com/xyz-abcd-efg',
    ])->assertStatus(409);
});
