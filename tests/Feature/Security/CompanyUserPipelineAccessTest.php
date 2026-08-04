<?php

declare(strict_types=1);

use App\Enums\AssignmentStage;
use App\Enums\CompanyMemberRole;
use App\Enums\UserRole;
use App\Models\CandidateProfile;
use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\User;
use App\Models\Vacancy;
use App\Models\VacancyAssignment;
use App\Models\VacancyAssignmentNote;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

/**
 * Probe: what can a company_user reach on the RECRUITER pipeline routes?
 *
 * The company frontend calls /vacancies/{id}/assignments (the recruiter
 * endpoint), not /me/company/vacancies/{id}/assignments. Those routes sit in a
 * plain `auth:sanctum` group with no role middleware, and AssignmentController
 * only calls authorize('view', $vacancy) — which VacancyPolicy grants to any
 * member of the owning company.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->companyUser = User::factory()->create();
    $this->companyUser->assignRole(UserRole::CompanyUser->value);

    $this->company = Company::factory()->create();
    CompanyMember::create([
        'company_id' => $this->company->id,
        'user_id' => $this->companyUser->id,
        'role' => CompanyMemberRole::Owner->value,
        'is_primary_contact' => true,
        'accepted_at' => now(),
    ]);

    $this->vacancy = Vacancy::factory()->create(['company_id' => $this->company->id]);

    $this->sourced = VacancyAssignment::factory()->create([
        'vacancy_id' => $this->vacancy->id,
        'candidate_profile_id' => CandidateProfile::factory()->create()->id,
        'stage' => AssignmentStage::Sourced,
    ]);
});

it('does not expose sourced assignments through the recruiter endpoint', function (): void {
    Sanctum::actingAs($this->companyUser);

    $response = $this->getJson("/api/v1/vacancies/{$this->vacancy->id}/assignments");

    // Either the route rejects the role outright, or it returns only the
    // stages a company is allowed to see. Both are acceptable; leaking is not.
    if ($response->status() === 200) {
        expect(collect($response->json('data'))->pluck('stage')->all())
            ->not->toContain('sourced');
    } else {
        expect($response->status())->toBe(403);
    }
});

it('does not let a company move a candidate between pipeline stages', function (): void {
    Sanctum::actingAs($this->companyUser);

    $this->patchJson("/api/v1/assignments/{$this->sourced->id}", [
        'stage' => AssignmentStage::Hired->value,
    ])->assertForbidden();
});

it('does not let a company assign candidates to its own vacancy', function (): void {
    Sanctum::actingAs($this->companyUser);

    $this->postJson("/api/v1/vacancies/{$this->vacancy->id}/assignments", [
        'candidate_profile_id' => CandidateProfile::factory()->create()->id,
    ])->assertForbidden();
});

it('does not let a company delete an assignment', function (): void {
    Sanctum::actingAs($this->companyUser);

    $this->deleteJson("/api/v1/assignments/{$this->sourced->id}")
        ->assertForbidden();
});

it('does not expose internal recruiter notes to the company', function (): void {
    VacancyAssignmentNote::factory()->create([
        'vacancy_assignment_id' => $this->sourced->id,
        'body' => 'Nota interna: el candidato pidió más de lo presupuestado.',
        'visibility' => 'internal',
    ]);

    Sanctum::actingAs($this->companyUser);

    $response = $this->getJson("/api/v1/assignments/{$this->sourced->id}/notes");

    if ($response->status() === 200) {
        expect($response->json('data'))->toBe([]);
    } else {
        expect($response->status())->toBe(403);
    }
});
