<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('verifies email with a valid hash', function (): void {
    $user = User::factory()->create([
        'email' => 'verify@humae.com.mx',
        'email_verified_at' => null,
    ]);

    $hash = sha1((string) $user->getEmailForVerification());

    $response = $this->getJson("/api/v1/auth/verify-email/{$user->id}/{$hash}");

    $response->assertOk()->assertJsonPath('success', true);
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('rejects invalid hash', function (): void {
    $user = User::factory()->create(['email_verified_at' => null]);

    $response = $this->getJson("/api/v1/auth/verify-email/{$user->id}/invalid-hash");

    $response->assertStatus(403);
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('resends verification email for authenticated unverified user', function (): void {
    Notification::fake();

    $user = User::factory()->create(['email_verified_at' => null]);
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/v1/auth/resend-verification');

    $response->assertOk();
    Notification::assertSentTo($user, VerifyEmail::class);
});
