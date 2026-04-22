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

    $this->getJson("/api/v1/me/company/vacancies/{$vacancy->id}")
        ->assertForbidden();
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

it('company_user publishes vacancy (borrador → activa)', function (): void {
    [$user, $company] = makeCompanyOwner();
    $vacancy = Vacancy::factory()->create([
        'company_id' => $company->id,
        'state' => VacancyState::Borrador,
        'published_at' => null,
    ]);
    Sanctum::actingAs($user);

    $this->postJson("/api/v1/me/company/vacancies/{$vacancy->id}/transition", [
        'to' => 'activa',
    ])
        ->assertOk()
        ->assertJsonPath('data.state', 'activa');

    expect($vacancy->fresh()->published_at)->not->toBeNull();
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

it('company_user cannot transition to stages outside publish/cancel', function (): void {
    [$user, $company] = makeCompanyOwner();
    $vacancy = Vacancy::factory()->create([
        'company_id' => $company->id,
        'state' => VacancyState::Activa,
    ]);
    Sanctum::actingAs($user);

    // en_busqueda no está permitido para company_user (solo publicar/cancelar)
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
