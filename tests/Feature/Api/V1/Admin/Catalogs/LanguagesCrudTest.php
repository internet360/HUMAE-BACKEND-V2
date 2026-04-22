<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\CandidateProfile;
use App\Models\Language;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function actAsForLanguages(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);
    Sanctum::actingAs($user);

    return $user;
}

it('admin lists languages including inactive', function (): void {
    actAsForLanguages(UserRole::Admin->value);
    Language::factory()->create(['is_active' => true, 'name' => 'Español']);
    Language::factory()->create(['is_active' => false, 'name' => 'Esperanto']);

    $this->getJson('/api/v1/admin/catalogs/languages')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('admin creates a language', function (): void {
    actAsForLanguages(UserRole::Admin->value);

    $response = $this->postJson('/api/v1/admin/catalogs/languages', [
        'code' => 'de',
        'name' => 'Alemán',
        'native_name' => 'Deutsch',
    ]);

    $response->assertCreated()->assertJsonPath('data.code', 'de');
    expect(Language::where('code', 'de')->exists())->toBeTrue();
});

it('admin cannot duplicate language code', function (): void {
    actAsForLanguages(UserRole::Admin->value);
    Language::factory()->create(['code' => 'en']);

    $this->postJson('/api/v1/admin/catalogs/languages', [
        'code' => 'en',
        'name' => 'English',
    ])->assertStatus(422);
});

it('admin updates a language', function (): void {
    actAsForLanguages(UserRole::Admin->value);
    $language = Language::factory()->create(['name' => 'Old', 'is_active' => true]);

    $this->patchJson("/api/v1/admin/catalogs/languages/{$language->id}", [
        'name' => 'Nuevo nombre',
        'is_active' => false,
    ])->assertOk()
        ->assertJsonPath('data.name', 'Nuevo nombre')
        ->assertJsonPath('data.is_active', false);
});

it('admin deletes an unused language', function (): void {
    actAsForLanguages(UserRole::Admin->value);
    $language = Language::factory()->create();

    $this->deleteJson("/api/v1/admin/catalogs/languages/{$language->id}")
        ->assertNoContent();
});

it('admin cannot delete a language used by a candidate (409)', function (): void {
    actAsForLanguages(UserRole::Admin->value);
    $language = Language::factory()->create();
    $profile = CandidateProfile::factory()->create();
    $profile->languages()->attach($language->id, ['level' => 'b2']);

    $this->deleteJson("/api/v1/admin/catalogs/languages/{$language->id}")
        ->assertStatus(409);

    expect(Language::find($language->id))->not->toBeNull();
});

it('candidate cannot manage languages catalog', function (): void {
    actAsForLanguages(UserRole::Candidate->value);
    $this->getJson('/api/v1/admin/catalogs/languages')->assertStatus(403);
});

it('recruiter cannot manage languages catalog', function (): void {
    actAsForLanguages(UserRole::Recruiter->value);
    $this->getJson('/api/v1/admin/catalogs/languages')->assertStatus(403);
});
