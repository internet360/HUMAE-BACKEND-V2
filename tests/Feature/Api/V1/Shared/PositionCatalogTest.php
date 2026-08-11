<?php

declare(strict_types=1);

use App\Models\FunctionalArea;
use App\Models\Position;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->itSystems = FunctionalArea::factory()->create(['code' => 'it_systems', 'name' => 'Sistemas']);
    $this->health = FunctionalArea::factory()->create(['code' => 'health', 'name' => 'Salud']);

    $this->developer = Position::factory()->create([
        'code' => 'software_developer',
        'name' => 'Desarrollador/Diseño de Software',
        'functional_area_id' => $this->itSystems->id,
        'sort_order' => 1,
        'is_active' => true,
    ]);

    $this->nurse = Position::factory()->create([
        'code' => 'nursing_technician',
        'name' => 'Técnico en Enfermería',
        'functional_area_id' => $this->health->id,
        'sort_order' => 2,
        'is_active' => true,
    ]);
});

it('requires authentication', function (): void {
    $this->getJson('/api/v1/catalogs/positions')->assertUnauthorized();
});

it('returns the position catalog with its functional area', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $response = $this->getJson('/api/v1/catalogs/positions');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.code', 'software_developer')
        ->assertJsonPath('data.0.functional_area_id', $this->itSystems->id)
        ->assertJsonPath('data.1.code', 'nursing_technician')
        ->assertJsonPath('data.1.functional_area_id', $this->health->id);
});

it('exposes only id, code, name and functional_area_id', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $first = $this->getJson('/api/v1/catalogs/positions')->json('data.0');

    expect(array_keys($first))
        ->toEqualCanonicalizing(['id', 'code', 'name', 'functional_area_id']);
});

it('filters by functional_area_id', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson("/api/v1/catalogs/positions?functional_area_id={$this->health->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.code', 'nursing_technician');
});

it('rejects an unknown functional_area_id', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/v1/catalogs/positions?functional_area_id=999999')
        ->assertStatus(422)
        ->assertJsonPath('errors.functional_area_id.0', fn (string $m): bool => $m !== '');
});

it('hides inactive positions', function (): void {
    Sanctum::actingAs(User::factory()->create());
    $this->nurse->update(['is_active' => false]);

    $this->getJson('/api/v1/catalogs/positions')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.code', 'software_developer');
});

it('returns positions ordered by sort_order', function (): void {
    Sanctum::actingAs(User::factory()->create());
    $this->nurse->update(['sort_order' => 0]);

    $this->getJson('/api/v1/catalogs/positions')
        ->assertOk()
        ->assertJsonPath('data.0.code', 'nursing_technician')
        ->assertJsonPath('data.1.code', 'software_developer');
});
