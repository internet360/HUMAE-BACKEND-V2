<?php

declare(strict_types=1);

use App\Enums\CandidateState;
use App\Enums\CompanyMemberRole;
use App\Enums\UserRole;
use App\Enums\VacancyState;
use App\Models\CandidateProfile;
use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\Interview;
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

it('company_user requests an interview with two proposed slots; state is propuesta', function (): void {
    ['user' => $user, 'assignment' => $assignment] = companyOwnerWithAssignment();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/v1/interviews', [
        'vacancy_assignment_id' => $assignment->id,
        'scheduled_at' => now()->addDays(2)->toIso8601String(),
        'alternate_scheduled_at' => now()->addDays(3)->toIso8601String(),
        'mode' => 'online',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.state', 'propuesta')
        ->assertJsonPath('data.mode', 'online');

    expect($response->json('data.alternate_scheduled_at'))->not->toBeNull();
    expect($response->json('data.meeting_url'))->toBeNull();
});

it('company_user cannot propose an interview without a second slot', function (): void {
    ['user' => $user, 'assignment' => $assignment] = companyOwnerWithAssignment();
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/interviews', [
        'vacancy_assignment_id' => $assignment->id,
        'scheduled_at' => now()->addDays(2)->toIso8601String(),
        'mode' => 'online',
    ])->assertUnprocessable()
        ->assertJsonPath('errors.alternate_scheduled_at.0', 'Como empresa, debes proponer dos horarios para que el candidato escoja uno.');
});

it('company_user cannot pass meeting_url when proposing an interview', function (): void {
    ['user' => $user, 'assignment' => $assignment] = companyOwnerWithAssignment();
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/interviews', [
        'vacancy_assignment_id' => $assignment->id,
        'scheduled_at' => now()->addDays(2)->toIso8601String(),
        'alternate_scheduled_at' => now()->addDays(3)->toIso8601String(),
        'mode' => 'online',
        'meeting_url' => 'https://meet.google.com/xyz-abcd-efg',
    ])->assertUnprocessable();
});

it('rejects presencial and telefonica modes', function (): void {
    ['user' => $user, 'assignment' => $assignment] = companyOwnerWithAssignment();
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/interviews', [
        'vacancy_assignment_id' => $assignment->id,
        'scheduled_at' => now()->addDays(2)->toIso8601String(),
        'alternate_scheduled_at' => now()->addDays(3)->toIso8601String(),
        'mode' => 'presencial',
    ])->assertUnprocessable();
});

it('company_user cannot request an interview for another company assignment', function (): void {
    ['user' => $userA] = companyOwnerWithAssignment();
    ['assignment' => $foreignAssignment] = companyOwnerWithAssignment();

    Sanctum::actingAs($userA);

    $this->postJson('/api/v1/interviews', [
        'vacancy_assignment_id' => $foreignAssignment->id,
        'scheduled_at' => now()->addDays(2)->toIso8601String(),
        'alternate_scheduled_at' => now()->addDays(3)->toIso8601String(),
        'mode' => 'online',
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
        'alternate_scheduled_at' => now()->addDays(3)->toIso8601String(),
        'mode' => 'online',
    ])->assertStatus(409);
});

it('candidate selects slot 2; alternate is cleared and scheduled_at swaps', function (): void {
    ['user' => $companyUser, 'assignment' => $assignment] = companyOwnerWithAssignment();
    Sanctum::actingAs($companyUser);

    $slot1 = now()->addDays(2)->startOfHour();
    $slot2 = now()->addDays(3)->startOfHour();

    $create = $this->postJson('/api/v1/interviews', [
        'vacancy_assignment_id' => $assignment->id,
        'scheduled_at' => $slot1->toIso8601String(),
        'alternate_scheduled_at' => $slot2->toIso8601String(),
        'mode' => 'online',
    ])->assertCreated();

    $interviewId = $create->json('data.id');
    $candidateUser = $assignment->fresh()->candidateProfile->user;

    Sanctum::actingAs($candidateUser);

    $this->postJson("/api/v1/interviews/{$interviewId}/select-slot", ['slot' => 2])
        ->assertOk()
        ->assertJsonPath('data.alternate_scheduled_at', null);

    $interview = Interview::find($interviewId);
    expect($interview->scheduled_at->equalTo($slot2))->toBeTrue();
    expect($interview->alternate_scheduled_at)->toBeNull();
});

it('recruiter adds meeting details after candidate selected slot', function (): void {
    ['user' => $companyUser, 'assignment' => $assignment] = companyOwnerWithAssignment();
    Sanctum::actingAs($companyUser);

    $createResponse = $this->postJson('/api/v1/interviews', [
        'vacancy_assignment_id' => $assignment->id,
        'scheduled_at' => now()->addDays(2)->toIso8601String(),
        'alternate_scheduled_at' => now()->addDays(3)->toIso8601String(),
        'mode' => 'online',
    ])->assertCreated();
    $interviewId = $createResponse->json('data.id');

    $candidateUser = $assignment->fresh()->candidateProfile->user;
    Sanctum::actingAs($candidateUser);
    $this->postJson("/api/v1/interviews/{$interviewId}/select-slot", ['slot' => 1])
        ->assertOk();

    $recruiter = User::factory()->create();
    $recruiter->assignRole(UserRole::Recruiter->value);
    Sanctum::actingAs($recruiter);

    $this->postJson("/api/v1/interviews/{$interviewId}/meeting-details", [
        'meeting_url' => 'https://meet.google.com/aaa-bbb-ccc',
    ])->assertOk()
        ->assertJsonPath('data.meeting_url', 'https://meet.google.com/aaa-bbb-ccc');
});

it('confirm fails on company-proposed interview without meeting_url', function (): void {
    ['user' => $companyUser, 'assignment' => $assignment] = companyOwnerWithAssignment();
    Sanctum::actingAs($companyUser);

    $createResponse = $this->postJson('/api/v1/interviews', [
        'vacancy_assignment_id' => $assignment->id,
        'scheduled_at' => now()->addDays(2)->toIso8601String(),
        'alternate_scheduled_at' => now()->addDays(3)->toIso8601String(),
        'mode' => 'online',
    ])->assertCreated();
    $interviewId = $createResponse->json('data.id');

    $candidateUser = $assignment->fresh()->candidateProfile->user;
    Sanctum::actingAs($candidateUser);
    $this->postJson("/api/v1/interviews/{$interviewId}/select-slot", ['slot' => 1])
        ->assertOk();

    // Sin meeting_url no se puede confirmar
    $this->postJson("/api/v1/interviews/{$interviewId}/confirm")
        ->assertStatus(409);
});
