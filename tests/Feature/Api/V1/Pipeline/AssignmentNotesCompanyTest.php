<?php

declare(strict_types=1);

use App\Enums\CompanyMemberRole;
use App\Enums\UserRole;
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

function assignmentForCompany(): array
{
    $user = User::factory()->create();
    $user->assignRole(UserRole::CompanyUser->value);
    $company = Company::factory()->create();
    CompanyMember::create([
        'company_id' => $company->id,
        'user_id' => $user->id,
        'role' => CompanyMemberRole::Owner->value,
    ]);
    $vacancy = Vacancy::factory()->create(['company_id' => $company->id]);
    $profile = CandidateProfile::factory()->create();
    $assignment = VacancyAssignment::factory()->create([
        'vacancy_id' => $vacancy->id,
        'candidate_profile_id' => $profile->id,
    ]);

    return [$user, $company, $assignment];
}

it('company_user can create a note; visibility is forced to company', function (): void {
    [$user, , $assignment] = assignmentForCompany();
    Sanctum::actingAs($user);

    $response = $this->postJson("/api/v1/assignments/{$assignment->id}/notes", [
        'body' => 'Nos interesa este candidato para reubicar.',
        'visibility' => 'internal', // should be overridden
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.visibility', 'company')
        ->assertJsonPath('data.body', 'Nos interesa este candidato para reubicar.');
});

it('company_user from a different company gets 403', function (): void {
    [$userA, , $assignment] = assignmentForCompany();
    [$userB] = assignmentForCompany(); // creates an independent assignment for company B

    Sanctum::actingAs($userB);

    // userB tries to access assignment from company A
    $this->postJson("/api/v1/assignments/{$assignment->id}/notes", [
        'body' => 'Should fail',
    ])->assertForbidden();

    $this->getJson("/api/v1/assignments/{$assignment->id}/notes")
        ->assertForbidden();

    expect($userA)->not->toBeNull(); // quiet unused
});

it('company_user listing notes sees only visibility=company', function (): void {
    [$user, , $assignment] = assignmentForCompany();

    $assignment->notes()->create([
        'author_id' => $user->id,
        'visibility' => 'internal',
        'body' => 'Secret internal note',
    ]);
    $assignment->notes()->create([
        'author_id' => $user->id,
        'visibility' => 'company',
        'body' => 'Empresa ve esta nota',
    ]);

    Sanctum::actingAs($user);

    $response = $this->getJson("/api/v1/assignments/{$assignment->id}/notes");
    $response->assertOk();

    $visibilities = collect($response->json('data'))->pluck('visibility')->all();
    expect($visibilities)->not->toContain('internal')
        ->and($visibilities)->toContain('company');
});

it('recruiter can still create a note with visibility=internal', function (): void {
    [, , $assignment] = assignmentForCompany();
    $recruiter = User::factory()->create();
    $recruiter->assignRole(UserRole::Recruiter->value);
    Sanctum::actingAs($recruiter);

    $response = $this->postJson("/api/v1/assignments/{$assignment->id}/notes", [
        'body' => 'Nota interna del recruiter',
        'visibility' => 'internal',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.visibility', 'internal');
});
