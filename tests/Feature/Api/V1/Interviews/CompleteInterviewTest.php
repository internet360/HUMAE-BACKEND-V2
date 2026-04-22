<?php

declare(strict_types=1);

use App\Enums\CandidateState;
use App\Enums\InterviewState;
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

function confirmedInterviewSetup(): Interview
{
    $company = Company::factory()->create();
    $vacancy = Vacancy::factory()->create([
        'company_id' => $company->id,
        'state' => VacancyState::EntrevistasEnCurso,
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

    return Interview::factory()->create([
        'vacancy_assignment_id' => $assignment->id,
        'state' => InterviewState::Confirmada,
        'scheduled_at' => now()->subHour(),
    ]);
}

function actAsRecruiterC(): User
{
    $user = User::factory()->create();
    $user->assignRole(UserRole::Recruiter->value);
    Sanctum::actingAs($user);

    return $user;
}

it('recruiter marks a confirmed interview as realizada with feedback', function (): void {
    actAsRecruiterC();
    $interview = confirmedInterviewSetup();

    $response = $this->postJson("/api/v1/interviews/{$interview->id}/complete", [
        'recruiter_feedback' => 'Buena actitud, comunicación clara, dominio técnico medio.',
        'recommendation' => 'advance',
        'rating' => 7,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.state', 'realizada');

    expect($interview->fresh()->recruiter_feedback)
        ->toBe('Buena actitud, comunicación clara, dominio técnico medio.')
        ->and($interview->fresh()->recommendation)->toBe('advance')
        ->and($interview->fresh()->rating)->toBe(7);
});

it('rejects complete if feedback is empty', function (): void {
    actAsRecruiterC();
    $interview = confirmedInterviewSetup();

    $this->postJson("/api/v1/interviews/{$interview->id}/complete", [
        'recruiter_feedback' => '',
        'recommendation' => 'advance',
    ])->assertStatus(422);
});

it('rejects complete if recommendation is not in enum', function (): void {
    actAsRecruiterC();
    $interview = confirmedInterviewSetup();

    $this->postJson("/api/v1/interviews/{$interview->id}/complete", [
        'recruiter_feedback' => 'Feedback válido',
        'recommendation' => 'foo',
    ])->assertStatus(422);
});

it('rejects complete from a non-confirmed state', function (): void {
    actAsRecruiterC();
    $interview = confirmedInterviewSetup();
    $interview->forceFill(['state' => InterviewState::Propuesta->value])->save();

    $this->postJson("/api/v1/interviews/{$interview->id}/complete", [
        'recruiter_feedback' => 'Feedback válido',
        'recommendation' => 'advance',
    ])->assertStatus(409);
});

it('company_user cannot mark interview as complete', function (): void {
    $company = Company::factory()->create();
    $user = User::factory()->create();
    $user->assignRole(UserRole::CompanyUser->value);
    CompanyMember::create([
        'company_id' => $company->id,
        'user_id' => $user->id,
        'role' => 'owner',
    ]);
    Sanctum::actingAs($user);

    $interview = confirmedInterviewSetup();

    $this->postJson("/api/v1/interviews/{$interview->id}/complete", [
        'recruiter_feedback' => 'Intentando como empresa',
        'recommendation' => 'advance',
    ])->assertForbidden();
});
