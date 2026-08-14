<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\SalaryCurrency;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

/**
 * Catálogo de monedas.
 *
 * Existe porque el sueldo final confirmado exige moneda y no había de dónde
 * elegirla: un 12% sobre 38,000 pesos y sobre 38,000 dólares no son el mismo
 * cobro, así que el campo no podía quedar opcional ni adivinarse.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('lists active currencies to any authenticated user', function (): void {
    SalaryCurrency::factory()->create(['code' => 'MXN', 'is_active' => true]);
    SalaryCurrency::factory()->create(['code' => 'USD', 'is_active' => true]);

    $user = User::factory()->create();
    $user->assignRole(UserRole::CompanyUser->value);
    Sanctum::actingAs($user);

    $response = $this->getJson('/api/v1/catalogs/salary-currencies')->assertOk();

    expect(collect($response->json('data'))->pluck('code')->all())
        ->toContain('MXN', 'USD');
});

it('hides currencies that are no longer in use', function (): void {
    SalaryCurrency::factory()->create(['code' => 'MXN', 'is_active' => true]);
    SalaryCurrency::factory()->create(['code' => 'ARS', 'is_active' => false]);

    $user = User::factory()->create();
    $user->assignRole(UserRole::Recruiter->value);
    Sanctum::actingAs($user);

    $codes = collect(
        $this->getJson('/api/v1/catalogs/salary-currencies')->assertOk()->json('data'),
    )->pluck('code')->all();

    expect($codes)->toContain('MXN')->not->toContain('ARS');
});

it('locks a guest out', function (): void {
    $this->getJson('/api/v1/catalogs/salary-currencies')->assertUnauthorized();
});
