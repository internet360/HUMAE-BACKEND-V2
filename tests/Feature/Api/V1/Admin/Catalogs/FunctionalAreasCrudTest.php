<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\FunctionalArea;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function actAsForFunctionalAreas(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);
    Sanctum::actingAs($user);

    return $user;
}

it('admin lists functional areas including inactive', function (): void {
    actAsForFunctionalAreas(UserRole::Admin->value);
    FunctionalArea::factory()->create(['is_active' => true, 'name' => 'Producción']);
    FunctionalArea::factory()->create(['is_active' => false, 'name' => 'Área legacy']);

    $this->getJson('/api/v1/admin/catalogs/functional-areas')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('admin creates a functional area', function (): void {
    actAsForFunctionalAreas(UserRole::Admin->value);

    $response = $this->postJson('/api/v1/admin/catalogs/functional-areas', [
        'code' => 'industrial_safety',
        'name' => 'Seguridad Industrial',
        'description' => 'Higiene y seguridad en planta.',
    ]);

    $response->assertCreated()->assertJsonPath('data.code', 'industrial_safety');
    expect(FunctionalArea::where('code', 'industrial_safety')->exists())->toBeTrue();
});

it('admin cannot duplicate functional area code', function (): void {
    actAsForFunctionalAreas(UserRole::Admin->value);
    FunctionalArea::factory()->create(['code' => 'quality']);

    $this->postJson('/api/v1/admin/catalogs/functional-areas', [
        'code' => 'quality',
        'name' => 'Calidad',
    ])->assertStatus(422);
});

it('admin rejects invalid code format', function (): void {
    actAsForFunctionalAreas(UserRole::Admin->value);

    $this->postJson('/api/v1/admin/catalogs/functional-areas', [
        'code' => 'Invalid Code!',
        'name' => 'Foo',
    ])->assertStatus(422);
});

it('admin updates a functional area', function (): void {
    actAsForFunctionalAreas(UserRole::Admin->value);
    $area = FunctionalArea::factory()->create(['name' => 'Antiguo', 'is_active' => true]);

    $this->patchJson("/api/v1/admin/catalogs/functional-areas/{$area->id}", [
        'name' => 'Nuevo',
        'is_active' => false,
    ])->assertOk()
        ->assertJsonPath('data.name', 'Nuevo')
        ->assertJsonPath('data.is_active', false);
});

it('admin deletes a functional area', function (): void {
    actAsForFunctionalAreas(UserRole::Admin->value);
    $area = FunctionalArea::factory()->create();

    $this->deleteJson("/api/v1/admin/catalogs/functional-areas/{$area->id}")
        ->assertNoContent();
});

it('candidate cannot manage functional areas catalog', function (): void {
    actAsForFunctionalAreas(UserRole::Candidate->value);
    $this->getJson('/api/v1/admin/catalogs/functional-areas')->assertStatus(403);
});

it('recruiter cannot manage functional areas catalog', function (): void {
    actAsForFunctionalAreas(UserRole::Recruiter->value);
    $this->getJson('/api/v1/admin/catalogs/functional-areas')->assertStatus(403);
});

it('public catalog endpoint returns active functional areas only', function (): void {
    actAsForFunctionalAreas(UserRole::Candidate->value);
    FunctionalArea::factory()->create(['is_active' => true, 'name' => 'Producción']);
    FunctionalArea::factory()->create(['is_active' => false, 'name' => 'Inactiva']);

    $response = $this->getJson('/api/v1/catalogs/functional-areas')->assertOk();

    $names = collect($response->json('data'))->pluck('name')->all();
    expect($names)->toContain('Producción')
        ->and($names)->not->toContain('Inactiva');
});
