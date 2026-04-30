<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Notifications\AccountApprovedNotification;
use App\Notifications\AccountRejectedNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->admin = User::factory()->create([
        'email' => 'admin-aprueba@humae.test',
        'status' => UserStatus::Active->value,
        'email_verified_at' => now(),
    ]);
    $this->admin->assignRole(UserRole::Admin->value);
});

it('admin approves a pending recruiter and notifies them', function (): void {
    Notification::fake();

    $pending = User::factory()->create([
        'email' => 'pending-rec@humae.test',
        'status' => UserStatus::PendingApproval->value,
        'email_verified_at' => now(),
    ]);
    $pending->assignRole(UserRole::Recruiter->value);

    Sanctum::actingAs($this->admin);

    $response = $this->postJson('/api/v1/admin/users/'.$pending->id.'/approve');

    $response->assertOk();

    expect($pending->fresh()->status)->toBe(UserStatus::Active->value);

    Notification::assertSentTo($pending, AccountApprovedNotification::class);
});

it('admin rejects a pending company_user with reason and notifies them', function (): void {
    Notification::fake();

    $pending = User::factory()->create([
        'email' => 'pending-empresa@humae.test',
        'status' => UserStatus::PendingApproval->value,
        'email_verified_at' => now(),
    ]);
    $pending->assignRole(UserRole::CompanyUser->value);

    Sanctum::actingAs($this->admin);

    $response = $this->postJson('/api/v1/admin/users/'.$pending->id.'/reject', [
        'reason' => 'Información incompleta — vuelve a registrarte con datos válidos.',
    ]);

    $response->assertOk();

    expect($pending->fresh()->status)->toBe(UserStatus::Inactive->value);

    Notification::assertSentTo(
        $pending,
        AccountRejectedNotification::class,
        function (AccountRejectedNotification $n): bool {
            return str_contains((string) $n->reason, 'Información incompleta');
        },
    );
});

it('cannot approve a user who is not pending', function (): void {
    $active = User::factory()->create([
        'email' => 'already-active@humae.test',
        'status' => UserStatus::Active->value,
        'email_verified_at' => now(),
    ]);
    $active->assignRole(UserRole::Recruiter->value);

    Sanctum::actingAs($this->admin);

    $response = $this->postJson('/api/v1/admin/users/'.$active->id.'/approve');

    $response->assertStatus(409);
});

it('only admins can approve or reject', function (): void {
    $recruiter = User::factory()->create([
        'email' => 'plain-recruiter@humae.test',
        'status' => UserStatus::Active->value,
        'email_verified_at' => now(),
    ]);
    $recruiter->assignRole(UserRole::Recruiter->value);

    $pending = User::factory()->create([
        'status' => UserStatus::PendingApproval->value,
        'email_verified_at' => now(),
    ]);
    $pending->assignRole(UserRole::Recruiter->value);

    Sanctum::actingAs($recruiter);

    $this->postJson('/api/v1/admin/users/'.$pending->id.'/approve')->assertStatus(403);
    $this->postJson('/api/v1/admin/users/'.$pending->id.'/reject')->assertStatus(403);
});

it('admin lists pending users with status filter', function (): void {
    $pending = User::factory()->create([
        'email' => 'pending-list@humae.test',
        'status' => UserStatus::PendingApproval->value,
    ]);
    $pending->assignRole(UserRole::Recruiter->value);

    Sanctum::actingAs($this->admin);

    $response = $this->getJson('/api/v1/admin/users?status=pending_approval');

    $response
        ->assertOk()
        ->assertJsonPath('data.0.email', 'pending-list@humae.test');
});
