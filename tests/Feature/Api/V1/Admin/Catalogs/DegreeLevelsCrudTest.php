<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\DegreeLevel;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function actAsForDegreeLevels(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);
    Sanctum::actingAs($user);

    return $user;
}

it('admin lists degree levels including inactive', function (): void {
    actAsForDegreeLevels(UserRole::Admin->value);
    DegreeLevel::factory()->create(['is_active' => true, 'name' => 'Licenciatura']);
    DegreeLevel::factory()->create(['is_active' => false, 'name' => 'Diplomado obsoleto']);

    $this->getJson('/api/v1/admin/catalogs/degree-levels')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('admin creates a degree level', function (): void {
    actAsForDegreeLevels(UserRole::Admin->value);

    $response = $this->postJson('/api/v1/admin/catalogs/degree-levels', [
        'code' => 'especialidad',
        'name' => 'Especialidad',
        'description' => 'Posgrado corto post-licenciatura.',
    ]);

    $response->assertCreated()->assertJsonPath('data.code', 'especialidad');
    expect(DegreeLevel::where('code', 'especialidad')->exists())->toBeTrue();
});

it('admin cannot duplicate degree level code', function (): void {
    actAsForDegreeLevels(UserRole::Admin->value);
    DegreeLevel::factory()->create(['code' => 'maestria']);

    $this->postJson('/api/v1/admin/catalogs/degree-levels', [
        'code' => 'maestria',
        'name' => 'Maestría',
    ])->assertStatus(422);
});

it('admin updates a degree level', function (): void {
    actAsForDegreeLevels(UserRole::Admin->value);
    $level = DegreeLevel::factory()->create(['name' => 'Old', 'is_active' => true]);

    $this->patchJson("/api/v1/admin/catalogs/degree-levels/{$level->id}", [
        'name' => 'Nuevo',
        'is_active' => false,
    ])->assertOk()
        ->assertJsonPath('data.name', 'Nuevo')
        ->assertJsonPath('data.is_active', false);
});

it('admin deletes a degree level', function (): void {
    actAsForDegreeLevels(UserRole::Admin->value);
    $level = DegreeLevel::factory()->create();

    $this->deleteJson("/api/v1/admin/catalogs/degree-levels/{$level->id}")
        ->assertNoContent();
});

it('candidate cannot manage degree levels catalog', function (): void {
    actAsForDegreeLevels(UserRole::Candidate->value);
    $this->getJson('/api/v1/admin/catalogs/degree-levels')->assertStatus(403);
});

it('recruiter cannot manage degree levels catalog', function (): void {
    actAsForDegreeLevels(UserRole::Recruiter->value);
    $this->getJson('/api/v1/admin/catalogs/degree-levels')->assertStatus(403);
});
