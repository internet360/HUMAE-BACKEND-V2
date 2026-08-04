<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\ContactSubmission;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function actAsForContactSubmissions(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);
    Sanctum::actingAs($user);

    return $user;
}

it('admin lists contact submissions, paginated', function (): void {
    actAsForContactSubmissions(UserRole::Admin->value);
    ContactSubmission::factory()->count(3)->create();

    $response = $this->getJson('/api/v1/admin/contact-submissions');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('meta.pagination.total', 3);
});

it('admin filters contact submissions by status', function (): void {
    actAsForContactSubmissions(UserRole::Admin->value);
    ContactSubmission::factory()->create(['status' => 'new']);
    ContactSubmission::factory()->create(['status' => 'closed']);

    $response = $this->getJson('/api/v1/admin/contact-submissions?status=closed');

    $response->assertOk()->assertJsonCount(1, 'data');
});

it('denies contact submissions listing to non-admin roles', function (): void {
    actAsForContactSubmissions(UserRole::Recruiter->value);
    $this->getJson('/api/v1/admin/contact-submissions')->assertStatus(403);

    actAsForContactSubmissions(UserRole::CompanyUser->value);
    $this->getJson('/api/v1/admin/contact-submissions')->assertStatus(403);

    actAsForContactSubmissions(UserRole::Candidate->value);
    $this->getJson('/api/v1/admin/contact-submissions')->assertStatus(403);
});

it('denies contact submissions listing to guests', function (): void {
    $this->getJson('/api/v1/admin/contact-submissions')->assertStatus(401);
});
