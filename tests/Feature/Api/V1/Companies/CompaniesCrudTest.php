<?php

declare(strict_types=1);

use App\Enums\CompanyMemberRole;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function actAs(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);
    Sanctum::actingAs($user);

    return $user;
}

it('recruiter lists companies', function (): void {
    actAs(UserRole::Recruiter->value);
    Company::factory()->count(3)->create();

    $response = $this->getJson('/api/v1/companies');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(3, 'data');
});

it('admin creates a company with auto slug', function (): void {
    actAs(UserRole::Admin->value);

    $response = $this->postJson('/api/v1/companies', [
        'legal_name' => 'Acme Corp S.A. de C.V.',
        'trade_name' => 'Acme Corp',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.legal_name', 'Acme Corp S.A. de C.V.');

    expect(Company::count())->toBe(1)
        ->and(Company::first()->slug)->toStartWith('acme-corp');
});

it('candidate cannot list companies', function (): void {
    actAs(UserRole::Candidate->value);

    $this->getJson('/api/v1/companies')->assertStatus(403);
});

it('recruiter can update any company; a company_user outside it never resolves the row', function (): void {
    $company = Company::factory()->create();

    actAs(UserRole::Recruiter->value);
    $this->patchJson("/api/v1/companies/{$company->id}", [
        'legal_name' => 'New Name S.A.',
    ])->assertOk();

    // The tenancy scope hides companies the caller is not a member of, so route
    // model binding fails before the policy runs: 404, not 403. Refusing
    // without confirming the row exists is the stronger answer.
    actAs(UserRole::CompanyUser->value);
    $this->patchJson("/api/v1/companies/{$company->id}", [
        'legal_name' => 'Hacked',
    ])->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| The two company surfaces
|--------------------------------------------------------------------------
|
| `/companies/*` is HUMAE's registry of its clients (§5.6, admin / recruiter);
| `/me/company` is a client's view of itself (§6, "propia"). Letting the client
| through the first one is F-01, F-06, F-07 and F-15 at once, because the rows
| carry `rfc`, the billing contact, `account_manager_id` and `internal_notes`
| of every other client.
|
*/

/**
 * @return array{0: User, 1: Company}
 */
function memberOfOwnCompany(CompanyMemberRole $role = CompanyMemberRole::Owner): array
{
    $user = User::factory()->create();
    $user->assignRole(UserRole::CompanyUser->value);
    $company = Company::factory()->create([
        'rfc' => 'RFCPROPIO1234',
        'internal_notes' => 'Notas internas de HUMAE sobre este cliente.',
    ]);
    CompanyMember::factory()->create([
        'company_id' => $company->id,
        'user_id' => $user->id,
        'role' => $role->value,
    ]);
    Sanctum::actingAs($user);

    return [$user, $company];
}

it('never lets a client company enumerate the client registry', function (): void {
    Company::factory()->count(2)->create();
    memberOfOwnCompany();

    $this->getJson('/api/v1/companies')->assertForbidden();
});

it('refuses a client company reading even its own record through the staff endpoint', function (): void {
    // The row resolves — it is hers, so the tenancy scope lets it through — and
    // the policy still refuses: this endpoint is HUMAE's registry (§5.6).
    [, $company] = memberOfOwnCompany();

    $this->getJson("/api/v1/companies/{$company->id}")->assertForbidden();
    $this->getJson("/api/v1/companies/{$company->id}/members")->assertForbidden();
    $this->postJson("/api/v1/companies/{$company->id}/members", [
        'user_id' => User::factory()->create()->id,
        'role' => 'viewer',
    ])->assertForbidden();
});

it('lets a client company edit its own profile but not HUMAE columns on it', function (): void {
    [, $company] = memberOfOwnCompany();

    $this->patchJson('/api/v1/me/company', ['trade_name' => 'Nombre comercial nuevo'])
        ->assertOk();

    foreach ([
        ['status' => 'archived'],
        ['internal_notes' => 'reescrito por la empresa'],
        ['account_manager_id' => User::factory()->create()->id],
        ['rfc' => 'RFCREESCRITO1'],
    ] as $payload) {
        $this->patchJson('/api/v1/me/company', $payload)->assertForbidden();
    }

    $fresh = Company::acrossCompanies()->findOrFail($company->id);

    expect($fresh->trade_name)->toBe('Nombre comercial nuevo')
        ->and($fresh->rfc)->toBe('RFCPROPIO1234')
        ->and($fresh->internal_notes)->toBe('Notas internas de HUMAE sobre este cliente.')
        ->and($fresh->status)->not->toBe('archived');
});

it('lets a viewer read its own company but not edit it', function (): void {
    memberOfOwnCompany(CompanyMemberRole::Viewer);

    $this->getJson('/api/v1/me/company')->assertOk();
    $this->patchJson('/api/v1/me/company', ['trade_name' => 'No'])->assertForbidden();
});
