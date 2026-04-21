<?php

declare(strict_types=1);

use App\Enums\CompanyMemberRole;
use App\Enums\UserRole;
use App\Enums\VacancyState;
use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\User;
use App\Models\Vacancy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('recruiter creates a vacancy in borrador with auto code + slug', function (): void {
    $recruiter = User::factory()->create();
    $recruiter->assignRole(UserRole::Recruiter->value);
    Sanctum::actingAs($recruiter);

    $company = Company::factory()->create();

    $response = $this->postJson('/api/v1/vacancies', [
        'company_id' => $company->id,
        'title' => 'Senior UX Designer',
        'description' => 'Diseñador senior para el equipo de onboarding.',
        'vacancies_count' => 1,
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.state', 'borrador')
        ->assertJsonPath('data.company_id', $company->id);

    $vacancy = Vacancy::first();
    expect($vacancy->code)->toStartWith('HUM-')
        ->and($vacancy->slug)->toStartWith('senior-ux-designer')
        ->and($vacancy->created_by)->toBe($recruiter->id);
});

it('company_user sees only vacancies from their companies via /me/company', function (): void {
    $userA = User::factory()->create();
    $userA->assignRole(UserRole::CompanyUser->value);

    $userB = User::factory()->create();
    $userB->assignRole(UserRole::CompanyUser->value);

    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    CompanyMember::create([
        'company_id' => $companyA->id,
        'user_id' => $userA->id,
        'role' => CompanyMemberRole::Owner->value,
    ]);
    CompanyMember::create([
        'company_id' => $companyB->id,
        'user_id' => $userB->id,
        'role' => CompanyMemberRole::Owner->value,
    ]);

    Vacancy::factory()->create([
        'company_id' => $companyA->id,
        'title' => 'A-only vacancy',
    ]);
    Vacancy::factory()->create([
        'company_id' => $companyB->id,
        'title' => 'B-only vacancy',
    ]);

    Sanctum::actingAs($userA);
    $response = $this->getJson('/api/v1/me/company/vacancies');

    $response->assertOk();
    $titles = collect($response->json('data'))->pluck('title')->all();
    expect($titles)->toContain('A-only vacancy')
        ->and($titles)->not->toContain('B-only vacancy');
});

it('rejects invalid state transitions', function (): void {
    $recruiter = User::factory()->create();
    $recruiter->assignRole(UserRole::Recruiter->value);
    Sanctum::actingAs($recruiter);

    $company = Company::factory()->create();
    $vacancy = Vacancy::factory()->create([
        'company_id' => $company->id,
        'state' => VacancyState::Borrador,
    ]);

    $response = $this->postJson("/api/v1/vacancies/{$vacancy->id}/transition", [
        'to' => 'cubierta', // no permitido desde borrador
    ]);

    $response->assertStatus(409)
        ->assertJsonPath('success', false);

    expect($vacancy->fresh()->state->value)->toBe('borrador');
});

it('accepts valid transition borrador → activa and publishes', function (): void {
    $recruiter = User::factory()->create();
    $recruiter->assignRole(UserRole::Recruiter->value);
    Sanctum::actingAs($recruiter);

    $company = Company::factory()->create();
    $vacancy = Vacancy::factory()->create([
        'company_id' => $company->id,
        'state' => VacancyState::Borrador,
        'published_at' => null,
    ]);

    $response = $this->postJson("/api/v1/vacancies/{$vacancy->id}/transition", [
        'to' => 'activa',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.state', 'activa');

    expect($vacancy->fresh()->published_at)->not->toBeNull();
});

it('allows cancellation from any non-terminal state', function (): void {
    $recruiter = User::factory()->create();
    $recruiter->assignRole(UserRole::Recruiter->value);
    Sanctum::actingAs($recruiter);

    $company = Company::factory()->create();
    $vacancy = Vacancy::factory()->create([
        'company_id' => $company->id,
        'state' => VacancyState::EnBusqueda,
    ]);

    $response = $this->postJson("/api/v1/vacancies/{$vacancy->id}/transition", [
        'to' => 'cancelada',
        'reason' => 'Retirada por la empresa',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.state', 'cancelada');

    expect($vacancy->fresh()->cancel_reason)->toBe('Retirada por la empresa');
});

it('candidates cannot list or create vacancies', function (): void {
    $candidate = User::factory()->create();
    $candidate->assignRole(UserRole::Candidate->value);
    Sanctum::actingAs($candidate);

    $company = Company::factory()->create();

    $this->getJson('/api/v1/vacancies')->assertStatus(403);
    $this->postJson('/api/v1/vacancies', [
        'company_id' => $company->id,
        'title' => 'Hacked',
        'description' => 'No debería permitir esto.',
    ])->assertStatus(403);
});
