<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Company;
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

it('recruiter can update any company, company_user cannot', function (): void {
    $company = Company::factory()->create();

    actAs(UserRole::Recruiter->value);
    $this->patchJson("/api/v1/companies/{$company->id}", [
        'legal_name' => 'New Name S.A.',
    ])->assertOk();

    actAs(UserRole::CompanyUser->value);
    $this->patchJson("/api/v1/companies/{$company->id}", [
        'legal_name' => 'Hacked',
    ])->assertStatus(403);
});
