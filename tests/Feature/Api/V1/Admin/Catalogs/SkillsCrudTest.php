<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\CandidateProfile;
use App\Models\Skill;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function actAsForSkills(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);
    Sanctum::actingAs($user);

    return $user;
}

it('admin lists skills including inactive', function (): void {
    actAsForSkills(UserRole::Admin->value);
    Skill::factory()->create(['is_active' => true, 'name' => 'React']);
    Skill::factory()->create(['is_active' => false, 'name' => 'Legacy VBA']);

    $response = $this->getJson('/api/v1/admin/catalogs/skills');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(2, 'data');
});

it('admin filters skills by q', function (): void {
    actAsForSkills(UserRole::Admin->value);
    Skill::factory()->create(['name' => 'TypeScript', 'code' => 'typescript']);
    Skill::factory()->create(['name' => 'Go', 'code' => 'go']);

    $response = $this->getJson('/api/v1/admin/catalogs/skills?q=type');

    $response->assertOk()->assertJsonCount(1, 'data');
});

it('admin creates a skill with valid data', function (): void {
    actAsForSkills(UserRole::Admin->value);

    $response = $this->postJson('/api/v1/admin/catalogs/skills', [
        'code' => 'terraform',
        'name' => 'Terraform',
        'category' => 'tecnica',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.code', 'terraform')
        ->assertJsonPath('data.is_active', true);

    expect(Skill::where('code', 'terraform')->exists())->toBeTrue();
});

it('admin cannot create skill with duplicate code', function (): void {
    actAsForSkills(UserRole::Admin->value);
    Skill::factory()->create(['code' => 'python']);

    $this->postJson('/api/v1/admin/catalogs/skills', [
        'code' => 'python',
        'name' => 'Python',
    ])->assertStatus(422);
});

it('admin rejects code with invalid characters', function (): void {
    actAsForSkills(UserRole::Admin->value);

    $this->postJson('/api/v1/admin/catalogs/skills', [
        'code' => 'My Skill!',
        'name' => 'My Skill',
    ])->assertStatus(422);
});

it('admin updates a skill (name + is_active toggle)', function (): void {
    actAsForSkills(UserRole::Admin->value);
    $skill = Skill::factory()->create(['is_active' => true, 'name' => 'Old Name']);

    $response = $this->patchJson("/api/v1/admin/catalogs/skills/{$skill->id}", [
        'name' => 'New Name',
        'is_active' => false,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.name', 'New Name')
        ->assertJsonPath('data.is_active', false);
});

it('admin deletes an unused skill (204)', function (): void {
    actAsForSkills(UserRole::Admin->value);
    $skill = Skill::factory()->create();

    $this->deleteJson("/api/v1/admin/catalogs/skills/{$skill->id}")
        ->assertNoContent();

    expect(Skill::find($skill->id))->toBeNull();
});

it('admin cannot delete a skill used by a candidate (409)', function (): void {
    actAsForSkills(UserRole::Admin->value);
    $skill = Skill::factory()->create();
    $profile = CandidateProfile::factory()->create();
    $profile->skills()->attach($skill->id, ['level' => 'intermedio']);

    $response = $this->deleteJson("/api/v1/admin/catalogs/skills/{$skill->id}");

    $response->assertStatus(409)
        ->assertJsonPath('success', false);

    expect(Skill::find($skill->id))->not->toBeNull();
});

it('candidate cannot access admin skills endpoints', function (): void {
    actAsForSkills(UserRole::Candidate->value);

    $this->getJson('/api/v1/admin/catalogs/skills')->assertStatus(403);
    $this->postJson('/api/v1/admin/catalogs/skills', [
        'code' => 'x', 'name' => 'X',
    ])->assertStatus(403);
});

it('recruiter cannot manage skill catalog', function (): void {
    actAsForSkills(UserRole::Recruiter->value);

    $this->getJson('/api/v1/admin/catalogs/skills')->assertStatus(403);
});
