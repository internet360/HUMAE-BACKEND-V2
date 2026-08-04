<?php

declare(strict_types=1);

use App\Enums\AssignmentStage;
use App\Enums\CompanyMemberRole;
use App\Enums\UserRole;
use App\Enums\VacancyState;
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
 * endpoint), not /me/company/vacancies/{id}/assignments. Those routes sat in a
 * plain `auth:sanctum` group with no role middleware, and AssignmentController
 * only called authorize('view', $vacancy) — which VacancyPolicy grants to any
 * member of the owning company.
 *
 * ARCHITECTURE.md §5.7 scopes the pipeline to recruiter/admin, with a single
 * exception: PATCH /assignments/{id}/select-finalist, which the company decides.
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

    $this->vacancy = Vacancy::factory()->create([
        'company_id' => $this->company->id,
        'state' => VacancyState::EntrevistasEnCurso,
    ]);

    // The factory stamps `presented_at` by default; a sourced candidate has
    // not been presented yet, and the stamp is what we assert stays untouched.
    $this->sourced = VacancyAssignment::factory()->create([
        'vacancy_id' => $this->vacancy->id,
        'candidate_profile_id' => CandidateProfile::factory()->create()->id,
        'stage' => AssignmentStage::Sourced,
        'presented_at' => null,
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

it('leaves the stage untouched in the database when a company tries to move a candidate', function (): void {
    Sanctum::actingAs($this->companyUser);

    $this->patchJson("/api/v1/assignments/{$this->sourced->id}", [
        'stage' => AssignmentStage::Presented->value,
    ])->assertForbidden();

    expect($this->sourced->fresh()->stage)->toBe(AssignmentStage::Sourced)
        ->and($this->sourced->fresh()->presented_at)->toBeNull();
});

it('keeps the assignment alive when a company tries to delete it', function (): void {
    Sanctum::actingAs($this->companyUser);

    $this->deleteJson("/api/v1/assignments/{$this->sourced->id}")->assertForbidden();

    expect(VacancyAssignment::whereKey($this->sourced->id)->exists())->toBeTrue();
});

it('does not let a company reach a sourced candidate through the notes thread', function (): void {
    // A note the company would be allowed to read — on a candidate it should
    // not even know exists. Reaching the thread confirms the candidate.
    VacancyAssignmentNote::factory()->create([
        'vacancy_assignment_id' => $this->sourced->id,
        'body' => 'Nota visible para empresa sobre un candidato aún interno.',
        'visibility' => 'company',
    ]);

    Sanctum::actingAs($this->companyUser);

    $this->getJson("/api/v1/assignments/{$this->sourced->id}/notes")->assertForbidden();
    $this->postJson("/api/v1/assignments/{$this->sourced->id}/notes", [
        'body' => 'Intento de nota sobre un candidato aún interno.',
    ])->assertForbidden();
});

it('does not let a company pick a finalist that was never presented to it', function (): void {
    Sanctum::actingAs($this->companyUser);

    $this->patchJson("/api/v1/assignments/{$this->sourced->id}/select-finalist")
        ->assertForbidden();
});

it('still lets the company pick the finalist among the candidates it was shown', function (): void {
    $interviewing = VacancyAssignment::factory()->create([
        'vacancy_id' => $this->vacancy->id,
        'candidate_profile_id' => CandidateProfile::factory()->create()->id,
        'stage' => AssignmentStage::Interviewing,
    ]);

    Sanctum::actingAs($this->companyUser);

    $this->patchJson("/api/v1/assignments/{$interviewing->id}/select-finalist")
        ->assertOk()
        ->assertJsonPath('data.stage', 'finalist');
});

/**
 * select-finalist is the single pipeline route a company may call, and it
 * answered with AssignmentResource — the INTERNAL view. `recruiter_notes` and
 * `rejection_reason` are HUMAE's own assessment of the candidate and are
 * separate DB columns from the visibility-scoped note thread, so the note
 * filtering above never covered them (§6, "Agregar notas internas — Empresa
 * cliente: ❌").
 */
it('does not leak recruiter_notes or rejection_reason in the select-finalist response', function (): void {
    $interviewing = VacancyAssignment::factory()->create([
        'vacancy_id' => $this->vacancy->id,
        'candidate_profile_id' => CandidateProfile::factory()->create()->id,
        'stage' => AssignmentStage::Interviewing,
        'recruiter_notes' => 'Pide 20% más de lo presupuestado; negociar a la baja.',
        'rejection_reason' => 'Motivo interno de descarte.',
    ]);

    Sanctum::actingAs($this->companyUser);

    $response = $this->patchJson("/api/v1/assignments/{$interviewing->id}/select-finalist")
        ->assertOk();

    expect($response->json('data'))
        ->not->toHaveKey('recruiter_notes')
        ->and($response->json('data'))->not->toHaveKey('rejection_reason');

    // Same row, same fields: a recruiter still gets them. The role is the only
    // difference, so the field is gated and not merely missing from the model.
    $recruiter = User::factory()->create();
    $recruiter->assignRole(UserRole::Recruiter->value);
    Sanctum::actingAs($recruiter);

    $this->getJson("/api/v1/vacancies/{$this->vacancy->id}/assignments")
        ->assertOk()
        ->assertJsonFragment([
            'recruiter_notes' => 'Pide 20% más de lo presupuestado; negociar a la baja.',
        ]);
});

it('still lets the company read company-visible notes of a presented candidate', function (): void {
    $presented = VacancyAssignment::factory()->create([
        'vacancy_id' => $this->vacancy->id,
        'candidate_profile_id' => CandidateProfile::factory()->create()->id,
        'stage' => AssignmentStage::Presented,
    ]);

    VacancyAssignmentNote::factory()->create([
        'vacancy_assignment_id' => $presented->id,
        'body' => 'Nota para la empresa.',
        'visibility' => 'company',
    ]);

    Sanctum::actingAs($this->companyUser);

    $response = $this->getJson("/api/v1/assignments/{$presented->id}/notes")->assertOk();

    expect(collect($response->json('data'))->pluck('body')->all())
        ->toContain('Nota para la empresa.');
});

it('locks a candidate out of every pipeline route', function (): void {
    $candidateUser = User::factory()->create();
    $candidateUser->assignRole(UserRole::Candidate->value);
    Sanctum::actingAs($candidateUser);

    // Two locks answer here, and they answer differently on purpose. Routes
    // carrying a {vacancy} are refused by the tenancy scope during binding
    // (404 — a candidate belongs to no company, so no vacancy exists for it);
    // routes carrying an {assignment} reach VacancyAssignmentPolicy (403).
    $this->getJson("/api/v1/vacancies/{$this->vacancy->id}/assignments")->assertNotFound();
    $this->postJson("/api/v1/vacancies/{$this->vacancy->id}/assignments", [
        'candidate_profile_id' => CandidateProfile::factory()->create()->id,
    ])->assertNotFound();
    $this->patchJson("/api/v1/assignments/{$this->sourced->id}", ['stage' => 'presented'])
        ->assertForbidden();
    $this->deleteJson("/api/v1/assignments/{$this->sourced->id}")->assertForbidden();
    $this->patchJson("/api/v1/assignments/{$this->sourced->id}/select-finalist")->assertForbidden();
    $this->getJson("/api/v1/assignments/{$this->sourced->id}/notes")->assertForbidden();
});

it('keeps the whole pipeline open to a recruiter', function (): void {
    $recruiter = User::factory()->create();
    $recruiter->assignRole(UserRole::Recruiter->value);
    Sanctum::actingAs($recruiter);

    $this->getJson("/api/v1/vacancies/{$this->vacancy->id}/assignments")->assertOk();
    $this->patchJson("/api/v1/assignments/{$this->sourced->id}", ['stage' => 'presented'])
        ->assertOk();
    $this->getJson("/api/v1/assignments/{$this->sourced->id}/notes")->assertOk();
    $this->deleteJson("/api/v1/assignments/{$this->sourced->id}")->assertNoContent();
});

it('keeps the whole pipeline open to an admin', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole(UserRole::Admin->value);
    Sanctum::actingAs($admin);

    $this->getJson("/api/v1/vacancies/{$this->vacancy->id}/assignments")->assertOk();
    $this->patchJson("/api/v1/assignments/{$this->sourced->id}", ['stage' => 'presented'])
        ->assertOk();
    $this->deleteJson("/api/v1/assignments/{$this->sourced->id}")->assertNoContent();
});
