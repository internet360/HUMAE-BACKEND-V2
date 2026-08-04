<?php

declare(strict_types=1);

use App\Enums\AssignmentStage;
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

/**
 * Probe: can a company_user reach a candidate through the INTERVIEW endpoints
 * that she cannot reach through the pipeline?
 *
 * The pipeline draws the confidentiality line at
 * `AssignmentStage::visibleToCompany()`: `sourced` is the recruiter's internal
 * short list and `rejected` are discards before presentation. Neither leaves
 * HUMAE (ARCHITECTURE.md §6 — "Ver candidatos asignados a vacante — Empresa
 * cliente: ✅ propia vacante", where "asignados" means presented).
 *
 * The interview endpoints gated a company_user on company membership over the
 * vacancy alone, never on the stage of the assignment. Since
 * `vacancy_assignment_id` is a sequential integer supplied by the caller, a
 * company owner could scan IDs, schedule an interview against a `sourced`
 * assignment on her own vacancy and read the candidate's name out of the
 * response — the internal short list, one POST at a time.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->owner = User::factory()->create();
    $this->owner->assignRole(UserRole::CompanyUser->value);

    $this->company = Company::factory()->create();
    CompanyMember::create([
        'company_id' => $this->company->id,
        'user_id' => $this->owner->id,
        'role' => CompanyMemberRole::Owner->value,
        'is_primary_contact' => true,
        'accepted_at' => now(),
    ]);

    $this->vacancy = Vacancy::factory()->create([
        'company_id' => $this->company->id,
        'state' => VacancyState::ConCandidatosAsignados->value,
    ]);

    $candidateUser = User::factory()->create();
    $candidateUser->assignRole(UserRole::Candidate->value);
    $this->candidate = CandidateProfile::factory()->create([
        'user_id' => $candidateUser->id,
        'state' => CandidateState::Activo->value,
        'first_name' => 'Secreto',
        'last_name' => 'Interno',
    ]);
});

function makeAssignment(AssignmentStage $stage): VacancyAssignment
{
    return VacancyAssignment::factory()->create([
        'vacancy_id' => test()->vacancy->id,
        'candidate_profile_id' => test()->candidate->id,
        'stage' => $stage->value,
    ]);
}

it('does not let a company schedule an interview against a candidate it was never presented', function (): void {
    $assignment = makeAssignment(AssignmentStage::Sourced);

    Sanctum::actingAs($this->owner);

    $this->postJson('/api/v1/interviews', [
        'vacancy_assignment_id' => $assignment->id,
        'scheduled_at' => now()->addWeek()->toIso8601String(),
        'alternate_scheduled_at' => now()->addWeek()->addDay()->toIso8601String(),
    ])->assertForbidden();

    expect(Interview::count())->toBe(0);
});

it('does not let a company schedule an interview against a rejected candidate', function (): void {
    $assignment = makeAssignment(AssignmentStage::Rejected);

    Sanctum::actingAs($this->owner);

    $this->postJson('/api/v1/interviews', [
        'vacancy_assignment_id' => $assignment->id,
        'scheduled_at' => now()->addWeek()->toIso8601String(),
        'alternate_scheduled_at' => now()->addWeek()->addDay()->toIso8601String(),
    ])->assertForbidden();

    expect(Interview::count())->toBe(0);
});

it('does not let a company read an interview of a candidate it was never presented', function (): void {
    $assignment = makeAssignment(AssignmentStage::Sourced);
    $interview = Interview::factory()->create([
        'vacancy_assignment_id' => $assignment->id,
    ]);

    Sanctum::actingAs($this->owner);

    $this->getJson("/api/v1/interviews/{$interview->id}")->assertForbidden();

    // Nor through the collection, which scopes by company but not by stage.
    $index = $this->getJson('/api/v1/interviews')->assertOk();
    expect($index->json('data'))->toBe([]);
});

it('does not let a company reschedule or cancel an interview of an unpresented candidate', function (): void {
    $assignment = makeAssignment(AssignmentStage::Sourced);
    $interview = Interview::factory()->create([
        'vacancy_assignment_id' => $assignment->id,
    ]);

    Sanctum::actingAs($this->owner);

    $this->patchJson("/api/v1/interviews/{$interview->id}", [
        'scheduled_at' => now()->addWeeks(2)->toIso8601String(),
    ])->assertForbidden();

    $this->postJson("/api/v1/interviews/{$interview->id}/cancel", [
        'reason' => 'probe',
    ])->assertForbidden();

    $this->postJson("/api/v1/interviews/{$interview->id}/select-slot", [
        'slot' => 1,
    ])->assertForbidden();
});

it('keeps the interview flow open once HUMAE presents the candidate', function (): void {
    $assignment = makeAssignment(AssignmentStage::Presented);

    Sanctum::actingAs($this->owner);

    // Same company, same vacancy, same candidate: the stage is the only
    // difference, so the gate is the stage and not some unrelated breakage.
    $response = $this->postJson('/api/v1/interviews', [
        'vacancy_assignment_id' => $assignment->id,
        'scheduled_at' => now()->addWeek()->toIso8601String(),
        'alternate_scheduled_at' => now()->addWeek()->addDay()->toIso8601String(),
    ])->assertCreated();

    $interviewId = $response->json('data.id');

    $this->getJson("/api/v1/interviews/{$interviewId}")->assertOk();
});

it('keeps every stage reachable for a recruiter', function (): void {
    $assignment = makeAssignment(AssignmentStage::Sourced);

    $recruiter = User::factory()->create();
    $recruiter->assignRole(UserRole::Recruiter->value);
    Sanctum::actingAs($recruiter);

    $this->postJson('/api/v1/interviews', [
        'vacancy_assignment_id' => $assignment->id,
        'scheduled_at' => now()->addWeek()->toIso8601String(),
    ])->assertCreated();
});
