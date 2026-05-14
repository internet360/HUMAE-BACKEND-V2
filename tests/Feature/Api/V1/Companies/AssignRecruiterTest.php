<?php

declare(strict_types=1);

use App\Enums\CompanyMemberRole;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\User;
use App\Models\Vacancy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function makeAssignRecruiterCompanyOwner(string $memberRole = 'owner'): array
{
    $user = User::factory()->create();
    $user->assignRole(UserRole::CompanyUser->value);
    $company = Company::factory()->create();
    CompanyMember::create([
        'company_id' => $company->id,
        'user_id' => $user->id,
        'role' => $memberRole,
    ]);

    return [$user, $company];
}

function makeRecruiterUser(string $status = 'active'): User
{
    $user = User::factory()->create(['status' => $status]);
    $user->assignRole(UserRole::Recruiter->value);

    return $user;
}

it('company owner can assign a valid recruiter to their vacancy', function (): void {
    [$user, $company] = makeAssignRecruiterCompanyOwner();
    $vacancy = Vacancy::factory()->create(['company_id' => $company->id]);
    $recruiter = makeRecruiterUser();
    Sanctum::actingAs($user);

    $this->postJson(
        "/api/v1/me/company/vacancies/{$vacancy->id}/assign-recruiter",
        ['recruiter_id' => $recruiter->id]
    )->assertOk();

    expect($vacancy->fresh()->assigned_recruiter_id)->toBe($recruiter->id);
});

it('company owner can clear the assigned recruiter (null)', function (): void {
    [$user, $company] = makeAssignRecruiterCompanyOwner();
    $recruiter = makeRecruiterUser();
    $vacancy = Vacancy::factory()->create([
        'company_id' => $company->id,
        'assigned_recruiter_id' => $recruiter->id,
    ]);
    Sanctum::actingAs($user);

    $this->postJson(
        "/api/v1/me/company/vacancies/{$vacancy->id}/assign-recruiter",
        ['recruiter_id' => null]
    )->assertOk();

    expect($vacancy->fresh()->assigned_recruiter_id)->toBeNull();
});

it('rejects assigning a non-recruiter user', function (): void {
    [$user, $company] = makeAssignRecruiterCompanyOwner();
    $vacancy = Vacancy::factory()->create(['company_id' => $company->id]);
    $candidate = User::factory()->create();
    $candidate->assignRole(UserRole::Candidate->value);
    Sanctum::actingAs($user);

    $this->postJson(
        "/api/v1/me/company/vacancies/{$vacancy->id}/assign-recruiter",
        ['recruiter_id' => $candidate->id]
    )->assertUnprocessable();
});

it('company viewer cannot assign a recruiter', function (): void {
    [$user, $company] = makeAssignRecruiterCompanyOwner(CompanyMemberRole::Viewer->value);
    $vacancy = Vacancy::factory()->create(['company_id' => $company->id]);
    $recruiter = makeRecruiterUser();
    Sanctum::actingAs($user);

    $this->postJson(
        "/api/v1/me/company/vacancies/{$vacancy->id}/assign-recruiter",
        ['recruiter_id' => $recruiter->id]
    )->assertForbidden();
});

it('company user cannot assign recruiter to another company vacancy', function (): void {
    [$userA] = makeAssignRecruiterCompanyOwner();
    [, $companyB] = makeAssignRecruiterCompanyOwner();
    $vacancy = Vacancy::factory()->create(['company_id' => $companyB->id]);
    $recruiter = makeRecruiterUser();
    Sanctum::actingAs($userA);

    $this->postJson(
        "/api/v1/me/company/vacancies/{$vacancy->id}/assign-recruiter",
        ['recruiter_id' => $recruiter->id]
    )->assertForbidden();
});

it('lists available active recruiters to company user', function (): void {
    [$user] = makeAssignRecruiterCompanyOwner();
    makeRecruiterUser();
    makeRecruiterUser();
    makeRecruiterUser(UserStatus::PendingApproval->value); // not active — excluded
    Sanctum::actingAs($user);

    $response = $this->getJson('/api/v1/recruiters/available')->assertOk();

    expect($response->json('data'))->toHaveCount(2);
});

it('rejects available-recruiters request from candidate', function (): void {
    $candidate = User::factory()->create();
    $candidate->assignRole(UserRole::Candidate->value);
    Sanctum::actingAs($candidate);

    $this->getJson('/api/v1/recruiters/available')->assertForbidden();
});
