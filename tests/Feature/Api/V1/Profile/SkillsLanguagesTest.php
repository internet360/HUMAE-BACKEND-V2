<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Language;
use App\Models\Skill;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('attaches a skill with level to the profile', function (): void {
    $user = User::factory()->create();
    $user->assignRole(UserRole::Candidate->value);
    Sanctum::actingAs($user);

    $skill = Skill::factory()->create();

    $response = $this->postJson('/api/v1/me/profile/skills', [
        'skill_id' => $skill->id,
        'level' => 'avanzado',
        'years_of_experience' => 3,
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('data.level', 'avanzado')
        ->assertJsonPath('data.years_of_experience', 3);
});

it('rejects invalid skill level', function (): void {
    $user = User::factory()->create();
    $user->assignRole(UserRole::Candidate->value);
    Sanctum::actingAs($user);

    $skill = Skill::factory()->create();

    $response = $this->postJson('/api/v1/me/profile/skills', [
        'skill_id' => $skill->id,
        'level' => 'guru',
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors('level');
});

it('detaches a skill', function (): void {
    $user = User::factory()->create();
    $user->assignRole(UserRole::Candidate->value);
    Sanctum::actingAs($user);

    $skill = Skill::factory()->create();

    $this->postJson('/api/v1/me/profile/skills', [
        'skill_id' => $skill->id,
        'level' => 'intermedio',
    ])->assertCreated();

    $this->deleteJson("/api/v1/me/profile/skills/{$skill->id}")->assertStatus(204);

    $list = $this->getJson('/api/v1/me/profile/skills')->json('data');
    expect($list)->toBeArray()->and($list)->toHaveCount(0);
});

it('attaches a language with CEFR level', function (): void {
    $user = User::factory()->create();
    $user->assignRole(UserRole::Candidate->value);
    Sanctum::actingAs($user);

    $language = Language::factory()->create();

    $response = $this->postJson('/api/v1/me/profile/languages', [
        'language_id' => $language->id,
        'level' => 'b2',
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('data.level', 'b2');
});
