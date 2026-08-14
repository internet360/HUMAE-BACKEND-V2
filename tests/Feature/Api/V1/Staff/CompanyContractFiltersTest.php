<?php

declare(strict_types=1);

use App\Enums\CompanyMemberRole;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\CompanyContract;
use App\Models\CompanyMember;
use App\Models\User;
use App\Models\Vacancy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function actingAsRole(UserRole $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role->value);
    Sanctum::actingAs($user);

    return $user;
}

it('filtra empresas por contrato maestro firmado', function (): void {
    $signed = Company::factory()->create(['legal_name' => 'Con Contrato SA']);
    CompanyContract::factory()->for($signed)->create();

    Company::factory()->create(['legal_name' => 'Sin Contrato SA']);

    actingAsRole(UserRole::Recruiter);

    $this->getJson('/api/v1/companies?contract_status=signed')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $signed->id);
});

it('cuenta como pendiente la empresa que sólo firmó una adenda', function (): void {
    $company = Company::factory()->create();
    $vacancy = Vacancy::factory()->for($company)->create(['fee_percentage' => 20]);

    // Adenda firmada, maestro no. La cláusula Primera sigue sin firmar por nadie.
    CompanyContract::factory()->for($company)->create(['vacancy_id' => $vacancy->id]);

    actingAsRole(UserRole::Recruiter);

    $this->getJson('/api/v1/companies?contract_status=pending')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $company->id);
});

it('informa cuántas adendas de honorarios quedan sin firmar', function (): void {
    $company = Company::factory()->create();
    CompanyContract::factory()->for($company)->create();

    Vacancy::factory()->for($company)->create(['fee_percentage' => 15]);
    Vacancy::factory()->for($company)->create(['fee_amount' => 40000]);
    // Sin honorario propio: se factura con el maestro, no hay nada que firmar.
    Vacancy::factory()->for($company)->create([
        'fee_percentage' => null,
        'fee_amount' => null,
    ]);

    actingAsRole(UserRole::Recruiter);

    $this->getJson('/api/v1/companies')
        ->assertOk()
        ->assertJsonPath('data.0.contract.is_signed', true)
        ->assertJsonPath('data.0.contract.pending_addenda', 2);
});

it('busca empresas por RFC y por correo de contacto', function (): void {
    $target = Company::factory()->create([
        'legal_name' => 'Alfa Servicios',
        'rfc' => 'ASE010203XYZ',
        'contact_email' => 'compras@alfa.mx',
    ]);
    Company::factory()->create(['legal_name' => 'Beta Corp']);

    actingAsRole(UserRole::Recruiter);

    $this->getJson('/api/v1/companies?q=ASE0102')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $target->id);

    $this->getJson('/api/v1/companies?q=compras@alfa')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $target->id);
});

it('limita per_page a 100 aunque pidan más', function (): void {
    Company::factory()->count(3)->create();

    actingAsRole(UserRole::Admin);

    $this->getJson('/api/v1/companies?per_page=100000')
        ->assertOk()
        ->assertJsonPath('meta.pagination.per_page', 100);
});

it('filtra usuarios por empresa', function (): void {
    $acme = Company::factory()->create();
    $other = Company::factory()->create();

    $acmeUser = User::factory()->create();
    $acmeUser->assignRole(UserRole::CompanyUser->value);
    CompanyMember::factory()->create([
        'company_id' => $acme->id,
        'user_id' => $acmeUser->id,
        'role' => CompanyMemberRole::Owner->value,
    ]);

    $otherUser = User::factory()->create();
    $otherUser->assignRole(UserRole::CompanyUser->value);
    CompanyMember::factory()->create([
        'company_id' => $other->id,
        'user_id' => $otherUser->id,
        'role' => CompanyMemberRole::Owner->value,
    ]);

    actingAsRole(UserRole::Admin);

    $response = $this->getJson("/api/v1/admin/users?company_id={$acme->id}")
        ->assertOk();

    $ids = collect($response->json('data'))->pluck('id')->all();

    expect($ids)->toContain($acmeUser->id)
        ->and($ids)->not->toContain($otherUser->id);
});
