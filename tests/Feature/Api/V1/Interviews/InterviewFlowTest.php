<?php

declare(strict_types=1);

use App\Enums\CandidateState;
use App\Enums\CompanyMemberRole;
use App\Enums\InterviewState;
use App\Enums\UserRole;
use App\Enums\VacancyState;
use App\Models\CandidateProfile;
use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\Interview;
use App\Models\InterviewReschedule;
use App\Models\User;
use App\Models\Vacancy;
use App\Models\VacancyAssignment;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function interviewSetup(VacancyState $vacancyState = VacancyState::ConCandidatosAsignados): array
{
    $candidateUser = User::factory()->create();
    $candidateUser->assignRole(UserRole::Candidate->value);
    $profile = CandidateProfile::factory()->create([
        'user_id' => $candidateUser->id,
        'state' => CandidateState::Activo->value,
    ]);

    $company = Company::factory()->create();

    $companyUser = User::factory()->create();
    $companyUser->assignRole(UserRole::CompanyUser->value);
    CompanyMember::create([
        'company_id' => $company->id,
        'user_id' => $companyUser->id,
        'role' => CompanyMemberRole::Owner->value,
    ]);

    $vacancy = Vacancy::factory()->create([
        'company_id' => $company->id,
        'state' => $vacancyState,
    ]);

    $assignment = VacancyAssignment::factory()->create([
        'vacancy_id' => $vacancy->id,
        'candidate_profile_id' => $profile->id,
    ]);

    return compact('candidateUser', 'profile', 'company', 'companyUser', 'vacancy', 'assignment');
}

function actAsRecruiterI(): User
{
    $user = User::factory()->create();
    $user->assignRole(UserRole::Recruiter->value);
    Sanctum::actingAs($user);

    return $user;
}

it('recruiter schedules an interview and auto-advances vacancy state', function (): void {
    actAsRecruiterI();
    ['assignment' => $assignment, 'vacancy' => $vacancy] = interviewSetup();

    $response = $this->postJson('/api/v1/interviews', [
        'vacancy_assignment_id' => $assignment->id,
        'scheduled_at' => now()->addDays(3)->toIso8601String(),
        'mode' => 'online',
        'meeting_url' => 'https://meet.google.com/abc-defg-hij',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.state', 'propuesta')
        ->assertJsonPath('data.round', 1);

    expect($vacancy->fresh()->state->value)->toBe('entrevistas_en_curso');
});

it('rejects scheduling when vacancy is not in a valid state', function (): void {
    actAsRecruiterI();
    ['assignment' => $assignment] = interviewSetup(VacancyState::Borrador);

    $response = $this->postJson('/api/v1/interviews', [
        'vacancy_assignment_id' => $assignment->id,
        'scheduled_at' => now()->addDays(3)->toIso8601String(),
    ]);

    $response->assertStatus(409);
});

it('candidate sees only their own interviews', function (): void {
    $scenario = interviewSetup();
    $mine = Interview::factory()->create([
        'vacancy_assignment_id' => $scenario['assignment']->id,
        'scheduled_at' => now()->addDays(2),
    ]);

    // Otra entrevista de otro candidato
    $other = interviewSetup();
    Interview::factory()->create([
        'vacancy_assignment_id' => $other['assignment']->id,
        'scheduled_at' => now()->addDays(4),
    ]);

    Sanctum::actingAs($scenario['candidateUser']);
    $response = $this->getJson('/api/v1/interviews');

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id')->all();
    expect($ids)->toContain($mine->id)
        ->and($ids)->toHaveCount(1);
});

it('candidate confirms their own interview', function (): void {
    $scenario = interviewSetup();
    $interview = Interview::factory()->create([
        'vacancy_assignment_id' => $scenario['assignment']->id,
        'state' => InterviewState::Propuesta,
        'scheduled_at' => now()->addDays(2),
    ]);

    Sanctum::actingAs($scenario['candidateUser']);
    $response = $this->postJson("/api/v1/interviews/{$interview->id}/confirm");

    $response->assertOk()->assertJsonPath('data.state', 'confirmada');
});

it('another candidate cannot confirm someone else interview', function (): void {
    $scenario = interviewSetup();
    $interview = Interview::factory()->create([
        'vacancy_assignment_id' => $scenario['assignment']->id,
        'state' => InterviewState::Propuesta,
        'scheduled_at' => now()->addDays(2),
    ]);

    $intruder = User::factory()->create();
    $intruder->assignRole(UserRole::Candidate->value);
    Sanctum::actingAs($intruder);

    $this->postJson("/api/v1/interviews/{$interview->id}/confirm")->assertStatus(403);
});

it('recruiter reschedules an interview and logs the previous date', function (): void {
    actAsRecruiterI();
    $scenario = interviewSetup();
    $original = now()->addDays(3);
    $interview = Interview::factory()->create([
        'vacancy_assignment_id' => $scenario['assignment']->id,
        'state' => InterviewState::Propuesta,
        'scheduled_at' => $original,
    ]);

    $newDate = now()->addDays(7);
    $response = $this->patchJson("/api/v1/interviews/{$interview->id}", [
        'scheduled_at' => $newDate->toIso8601String(),
        'reason' => 'Conflicto de agenda',
    ]);

    $response->assertOk()->assertJsonPath('data.state', 'propuesta');

    $fresh = $interview->fresh();
    expect($fresh->scheduled_at->toDateTimeString())
        ->toBe($newDate->startOfSecond()->toDateTimeString());

    expect(InterviewReschedule::where('interview_id', $interview->id)->count())->toBe(1);
});

it('cancels an interview with a reason', function (): void {
    $scenario = interviewSetup();
    $interview = Interview::factory()->create([
        'vacancy_assignment_id' => $scenario['assignment']->id,
        'state' => InterviewState::Confirmada,
        'scheduled_at' => now()->addDays(3),
    ]);

    Sanctum::actingAs($scenario['companyUser']);
    $response = $this->postJson("/api/v1/interviews/{$interview->id}/cancel", [
        'reason' => 'Candidato no está disponible.',
    ]);

    $response->assertOk()->assertJsonPath('data.state', 'cancelada');
});

it('rejects confirm transition from invalid state', function (): void {
    $scenario = interviewSetup();
    $interview = Interview::factory()->create([
        'vacancy_assignment_id' => $scenario['assignment']->id,
        'state' => InterviewState::Cancelada,
        'scheduled_at' => now()->addDays(1),
    ]);

    Sanctum::actingAs($scenario['candidateUser']);
    $response = $this->postJson("/api/v1/interviews/{$interview->id}/confirm");

    $response->assertStatus(409);
});

it('company_user only sees interviews from their own company', function (): void {
    $mine = interviewSetup();
    Interview::factory()->create([
        'vacancy_assignment_id' => $mine['assignment']->id,
        'scheduled_at' => now()->addDays(2),
    ]);

    $other = interviewSetup();
    Interview::factory()->create([
        'vacancy_assignment_id' => $other['assignment']->id,
        'scheduled_at' => now()->addDays(4),
    ]);

    Sanctum::actingAs($mine['companyUser']);
    $response = $this->getJson('/api/v1/interviews');

    $response->assertOk();
    expect(collect($response->json('data'))->count())->toBe(1);
});

it('rejects unauthenticated interview access', function (): void {
    $this->getJson('/api/v1/interviews')->assertStatus(401);
});
