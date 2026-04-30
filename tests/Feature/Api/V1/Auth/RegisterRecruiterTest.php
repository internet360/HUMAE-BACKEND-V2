<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Notifications\PendingUserRegistrationNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    // Admin que debe recibir la notificación.
    $admin = User::factory()->create([
        'email' => 'admin-test@humae.test',
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $admin->assignRole(UserRole::Admin->value);
});

it('registers a recruiter as pending_approval and notifies admins', function (): void {
    Notification::fake();
    Event::fake([Registered::class]);

    $response = $this->postJson('/api/v1/auth/register/recruiter', [
        'name' => 'Recluta Test',
        'email' => 'recluta@humae.test',
        'phone' => '+52 55 0000 0000',
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
        'accept_terms' => true,
        'motivo' => 'Trabajo en una agencia y quiero usar HUMAE.',
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('data.pending_approval', true)
        ->assertJsonPath('data.user.email', 'recluta@humae.test');

    $user = User::where('email', 'recluta@humae.test')->firstOrFail();

    expect($user->status)->toBe(UserStatus::PendingApproval->value)
        ->and($user->hasRole(UserRole::Recruiter->value))->toBeTrue();

    Event::assertDispatched(Registered::class);

    $admin = User::where('email', 'admin-test@humae.test')->firstOrFail();
    Notification::assertSentTo($admin, PendingUserRegistrationNotification::class);
});

it('rejects duplicated email when registering as recruiter', function (): void {
    User::factory()->create(['email' => 'taken@humae.test']);

    $response = $this->postJson('/api/v1/auth/register/recruiter', [
        'name' => 'Otro',
        'email' => 'taken@humae.test',
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
        'accept_terms' => true,
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['email']);
});
