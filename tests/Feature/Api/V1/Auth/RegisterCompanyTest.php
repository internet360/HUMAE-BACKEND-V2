<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
|--------------------------------------------------------------------------
| Company self-registration does not exist (F-12)
|--------------------------------------------------------------------------
|
| This file used to assert the opposite: that `POST /auth/register/company`
| created a company_user, a Company and an owner pivot in one public call. It
| is inverted, and named for the rule that decides it.
|
| ARCHITECTURE.md §6, row "Registrarse": Candidato ✅, Empresa cliente
| ❌ (invitación). A client company is onboarded by HUMAE, which is the whole
| premise of §1 — HUMAE curates both sides of the marketplace. `pending_approval`
| made the endpoint safe, not correct: it still let anyone mint a Company row
| and a company_user account, and there is no §5 route for it.
|
| The supported path is POST /admin/users, which issues the invitation token
| that /auth/invitation/accept consumes.
|
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('does not expose a public company registration endpoint', function (): void {
    $response = $this->postJson('/api/v1/auth/register/company', [
        'name' => 'Owner Empresa',
        'email' => 'owner@empresa.test',
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
        'accept_terms' => true,
        'company' => ['legal_name' => 'Empresa Demo S.A. de C.V.'],
    ]);

    $response->assertNotFound();

    expect(User::where('email', 'owner@empresa.test')->exists())->toBeFalse()
        ->and(Company::acrossCompanies()->count())->toBe(0);
});

it('onboards a client company through the HUMAE invitation flow instead', function (): void {
    $admin = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
    $admin->assignRole(UserRole::Admin->value);
    $company = Company::factory()->create();

    $this->actingAs($admin)->postJson('/api/v1/admin/users', [
        'name' => 'Contacto de la empresa',
        'email' => 'contacto@empresa.test',
        'role' => UserRole::CompanyUser->value,
        'company_id' => $company->id,
        'company_member_role' => 'owner',
    ])->assertCreated();

    $invited = User::where('email', 'contacto@empresa.test')->firstOrFail();

    expect($invited->hasRole(UserRole::CompanyUser->value))->toBeTrue()
        ->and($invited->invitation_token)->not->toBeNull();
});
