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
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function makeCompanyOwner(): array
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

it('company_user can show their own vacancy', function (): void {
    [$user, $company] = makeCompanyOwner();
    $vacancy = Vacancy::factory()->create(['company_id' => $company->id]);
    Sanctum::actingAs($user);

    $this->getJson("/api/v1/me/company/vacancies/{$vacancy->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $vacancy->id)
        ->assertJsonPath('data.company_id', $company->id);
});

it('company_user cannot show vacancy from another company', function (): void {
    [$userA] = makeCompanyOwner();
    [, $companyB] = makeCompanyOwner();
    $vacancy = Vacancy::factory()->create(['company_id' => $companyB->id]);
    Sanctum::actingAs($userA);

    // Another tenant's vacancy is scoped out of route model binding, so the
    // request dies at resolution with a 404 instead of reaching the policy.
    $this->getJson("/api/v1/me/company/vacancies/{$vacancy->id}")
        ->assertNotFound();
});

it('company_user can update vacancy in non-terminal state', function (): void {
    [$user, $company] = makeCompanyOwner();
    $vacancy = Vacancy::factory()->create([
        'company_id' => $company->id,
        'state' => VacancyState::Borrador,
    ]);
    Sanctum::actingAs($user);

    $this->patchJson("/api/v1/me/company/vacancies/{$vacancy->id}", [
        'title' => 'Nuevo título',
    ])
        ->assertOk()
        ->assertJsonPath('data.title', 'Nuevo título');

    expect($vacancy->fresh()->title)->toBe('Nuevo título');
});

it('company_user cannot edit vacancy in terminal state (cubierta)', function (): void {
    [$user, $company] = makeCompanyOwner();
    $vacancy = Vacancy::factory()->create([
        'company_id' => $company->id,
        'state' => VacancyState::Cubierta,
    ]);
    Sanctum::actingAs($user);

    $this->patchJson("/api/v1/me/company/vacancies/{$vacancy->id}", [
        'title' => 'Blocked',
    ])->assertStatus(422);
});

/*
 * Inverted against the previous expectation, and named for the rule that
 * decides it. ARCHITECTURE.md §6 reads "Aprobar / activar vacante — Empresa
 * cliente: ❌": the company files a request and HUMAE decides it goes live.
 * The controller used to allow exactly this and block the transition §6 does
 * grant the company (`cubierta`) — the whitelist was inverted (F-10).
 */
it('company_user cannot activate its own vacancy — approving is HUMAE\'s (§6)', function (): void {
    [$user, $company] = makeCompanyOwner();
    $vacancy = Vacancy::factory()->create([
        'company_id' => $company->id,
        'state' => VacancyState::Borrador,
        'published_at' => null,
    ]);
    Sanctum::actingAs($user);

    $this->postJson("/api/v1/me/company/vacancies/{$vacancy->id}/transition", [
        'to' => 'activa',
    ])->assertForbidden();

    expect($vacancy->fresh()->state)->toBe(VacancyState::Borrador)
        ->and($vacancy->fresh()->published_at)->toBeNull();
});

it('recruiter activates a company vacancy (§6 «Aprobar / activar vacante — Reclutador ✅»)', function (): void {
    [, $company] = makeCompanyOwner();
    $vacancy = Vacancy::factory()->create([
        'company_id' => $company->id,
        'state' => VacancyState::Borrador,
        'published_at' => null,
    ]);

    $recruiter = User::factory()->create();
    $recruiter->assignRole(UserRole::Recruiter->value);
    Sanctum::actingAs($recruiter);

    $this->postJson("/api/v1/vacancies/{$vacancy->id}/transition", ['to' => 'activa'])
        ->assertOk()
        ->assertJsonPath('data.state', 'activa');

    expect($vacancy->fresh()->published_at)->not->toBeNull();
});

it('company_user proposes the close (§6 «Marcar vacante como cubierta — Empresa ✅ (propone)»)', function (): void {
    [$user, $company] = makeCompanyOwner();
    $vacancy = Vacancy::factory()->create([
        'company_id' => $company->id,
        'state' => VacancyState::FinalistaSeleccionado,
    ]);
    Sanctum::actingAs($user);

    $this->postJson("/api/v1/me/company/vacancies/{$vacancy->id}/transition", [
        'to' => 'cubierta',
    ])
        ->assertOk()
        ->assertJsonPath('data.state', 'cubierta');

    expect($vacancy->fresh()->filled_at)->not->toBeNull();
});

it('company_user cancels vacancy with reason', function (): void {
    [$user, $company] = makeCompanyOwner();
    $vacancy = Vacancy::factory()->create([
        'company_id' => $company->id,
        'state' => VacancyState::Activa,
    ]);
    Sanctum::actingAs($user);

    $this->postJson("/api/v1/me/company/vacancies/{$vacancy->id}/transition", [
        'to' => 'cancelada',
        'reason' => 'Presupuesto aplazado',
    ])
        ->assertOk()
        ->assertJsonPath('data.state', 'cancelada');

    expect($vacancy->fresh()->cancel_reason)->toBe('Presupuesto aplazado');
});

it('company_user cannot drive the internal search states', function (): void {
    [$user, $company] = makeCompanyOwner();
    $vacancy = Vacancy::factory()->create([
        'company_id' => $company->id,
        'state' => VacancyState::Activa,
    ]);
    Sanctum::actingAs($user);

    // `en_busqueda` describes HUMAE's own progress on the mandate (§5.7), so it
    // is behind VacancyPolicy::advance. The company owns `close` and `cancel`.
    $this->postJson("/api/v1/me/company/vacancies/{$vacancy->id}/transition", [
        'to' => 'en_busqueda',
    ])->assertForbidden();
});

it('company_user lists presented-or-later assignments only, hiding sourced/rejected', function (): void {
    [$user, $company] = makeCompanyOwner();
    $vacancy = Vacancy::factory()->create(['company_id' => $company->id]);

    $profile1 = CandidateProfile::factory()->create();
    $profile2 = CandidateProfile::factory()->create();
    $profile3 = CandidateProfile::factory()->create();

    VacancyAssignment::factory()->create([
        'vacancy_id' => $vacancy->id,
        'candidate_profile_id' => $profile1->id,
        'stage' => AssignmentStage::Sourced,
    ]);
    VacancyAssignment::factory()->create([
        'vacancy_id' => $vacancy->id,
        'candidate_profile_id' => $profile2->id,
        'stage' => AssignmentStage::Presented,
    ]);
    VacancyAssignment::factory()->create([
        'vacancy_id' => $vacancy->id,
        'candidate_profile_id' => $profile3->id,
        'stage' => AssignmentStage::Rejected,
    ]);

    Sanctum::actingAs($user);

    $response = $this->getJson("/api/v1/me/company/vacancies/{$vacancy->id}/assignments");
    $response->assertOk();

    $stages = collect($response->json('data'))->pluck('stage')->all();
    expect($stages)->toContain('presented')
        ->and($stages)->not->toContain('sourced')
        ->and($stages)->not->toContain('rejected');
});

it('company assignments resource omits contact PII of candidate', function (): void {
    [$user, $company] = makeCompanyOwner();
    $vacancy = Vacancy::factory()->create(['company_id' => $company->id]);

    $profile = CandidateProfile::factory()->create([
        'contact_email' => 'secret@example.com',
        'contact_phone' => '+52 55 1111 2222',
    ]);
    VacancyAssignment::factory()->create([
        'vacancy_id' => $vacancy->id,
        'candidate_profile_id' => $profile->id,
        'stage' => AssignmentStage::Presented,
    ]);

    Sanctum::actingAs($user);

    $response = $this->getJson("/api/v1/me/company/vacancies/{$vacancy->id}/assignments");
    $response->assertOk();

    $candidate = $response->json('data.0.candidate');
    expect($candidate)->not->toHaveKey('contact_email')
        ->and($candidate)->not->toHaveKey('contact_phone');
});

it('company_user cannot reach hidden stages through the stage filter', function (): void {
    [$user, $company] = makeCompanyOwner();
    $vacancy = Vacancy::factory()->create(['company_id' => $company->id]);

    VacancyAssignment::factory()->create([
        'vacancy_id' => $vacancy->id,
        'candidate_profile_id' => CandidateProfile::factory()->create()->id,
        'stage' => AssignmentStage::Sourced,
    ]);
    VacancyAssignment::factory()->create([
        'vacancy_id' => $vacancy->id,
        'candidate_profile_id' => CandidateProfile::factory()->create()->id,
        'stage' => AssignmentStage::Rejected,
    ]);

    Sanctum::actingAs($user);

    // Asking for a hidden stage must return nothing, never the hidden rows and
    // never a silent fallback to the full visible list.
    foreach (['sourced', 'rejected', 'withdrawn'] as $hidden) {
        $response = $this->getJson(
            "/api/v1/me/company/vacancies/{$vacancy->id}/assignments?stage={$hidden}",
        );
        $response->assertOk();
        expect($response->json('data'))->toBe([]);
    }
});

it('company_user stage filter still narrows within the visible set', function (): void {
    [$user, $company] = makeCompanyOwner();
    $vacancy = Vacancy::factory()->create(['company_id' => $company->id]);

    VacancyAssignment::factory()->create([
        'vacancy_id' => $vacancy->id,
        'candidate_profile_id' => CandidateProfile::factory()->create()->id,
        'stage' => AssignmentStage::Presented,
    ]);
    VacancyAssignment::factory()->create([
        'vacancy_id' => $vacancy->id,
        'candidate_profile_id' => CandidateProfile::factory()->create()->id,
        'stage' => AssignmentStage::Finalist,
    ]);

    Sanctum::actingAs($user);

    $response = $this->getJson(
        "/api/v1/me/company/vacancies/{$vacancy->id}/assignments?stage=finalist",
    );
    $response->assertOk();

    expect(collect($response->json('data'))->pluck('stage')->all())->toBe(['finalist']);
});

it('recruiter visibility is unaffected by the company stage restriction', function (): void {
    [, $company] = makeCompanyOwner();
    $vacancy = Vacancy::factory()->create(['company_id' => $company->id]);

    VacancyAssignment::factory()->create([
        'vacancy_id' => $vacancy->id,
        'candidate_profile_id' => CandidateProfile::factory()->create()->id,
        'stage' => AssignmentStage::Sourced,
    ]);

    $recruiter = User::factory()->create();
    $recruiter->assignRole(UserRole::Recruiter->value);
    Sanctum::actingAs($recruiter);

    $response = $this->getJson("/api/v1/vacancies/{$vacancy->id}/assignments");
    $response->assertOk();

    expect(collect($response->json('data'))->pluck('stage')->all())->toContain('sourced');
});
