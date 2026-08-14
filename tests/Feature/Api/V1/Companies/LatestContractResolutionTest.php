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

/**
 * `Company::latestContract` resuelve el contrato MAESTRO vigente.
 *
 * Este archivo existe por un bug concreto: la condición `whereNull('vacancy_id')`
 * estaba encadenada en la relación en vez de dentro de la subconsulta de
 * `ofMany`, y `ofMany` ignora los `where` de la relación al calcular su máximo.
 * Bastaba con que una empresa firmara una adenda DESPUÉS de su maestro para que
 * la relación devolviera `null` y el sistema entero creyera que esa empresa no
 * había firmado nada.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('encuentra el maestro aunque la adenda se haya firmado después', function (): void {
    $company = Company::factory()->create();

    $master = CompanyContract::factory()->for($company)->create([
        'vacancy_id' => null,
        'signed_at' => now()->subHours(2),
    ]);

    $vacancy = Vacancy::factory()->for($company)->create(['fee_percentage' => 20]);
    CompanyContract::factory()->for($company)->create([
        'vacancy_id' => $vacancy->id,
        'signed_at' => now(),
    ]);

    expect($company->fresh()?->latestContract?->id)->toBe($master->id);
});

it('devuelve el maestro más reciente cuando hubo renegociación', function (): void {
    $company = Company::factory()->create();

    CompanyContract::factory()->for($company)->create([
        'vacancy_id' => null,
        'signed_at' => now()->subYear(),
    ]);
    $renegotiated = CompanyContract::factory()->for($company)->create([
        'vacancy_id' => null,
        'signed_at' => now()->subDay(),
    ]);

    expect($company->fresh()?->latestContract?->id)->toBe($renegotiated->id);
});

it('no toma una adenda como contrato maestro', function (): void {
    $company = Company::factory()->create();
    $vacancy = Vacancy::factory()->for($company)->create(['fee_percentage' => 20]);

    CompanyContract::factory()->for($company)->create(['vacancy_id' => $vacancy->id]);

    expect($company->fresh()?->latestContract)->toBeNull();
});

it('deja de ver el maestro anulado', function (): void {
    $company = Company::factory()->create();
    $master = CompanyContract::factory()->for($company)->create(['vacancy_id' => null]);
    $master->delete();

    expect($company->fresh()?->latestContract)->toBeNull();
});

it('sigue respondiendo 409 a una segunda firma tras haber firmado una adenda', function (): void {
    $company = Company::factory()->create();

    CompanyContract::factory()->for($company)->create([
        'vacancy_id' => null,
        'signed_at' => now()->subHours(2),
    ]);

    $vacancy = Vacancy::factory()->for($company)->create(['fee_percentage' => 20]);
    CompanyContract::factory()->for($company)->create([
        'vacancy_id' => $vacancy->id,
        'signed_at' => now(),
    ]);

    $user = User::factory()->create();
    $user->assignRole(UserRole::CompanyUser->value);
    CompanyMember::factory()->create([
        'company_id' => $company->id,
        'user_id' => $user->id,
        'role' => CompanyMemberRole::Owner->value,
    ]);
    Sanctum::actingAs($user);

    // El síntoma que hacía visible el bug: la empresa veía otra vez el asistente
    // de firma de un contrato que ya tenía.
    $this->getJson('/api/v1/me/company/contract')
        ->assertOk()
        ->assertJsonPath('meta.is_signed', true)
        ->assertJsonPath('meta.can_sign', false);
});
