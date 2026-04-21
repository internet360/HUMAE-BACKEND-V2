<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('sends a reset link for existing email', function (): void {
    Notification::fake();

    $user = User::factory()->create(['email' => 'reset@humae.com.mx']);

    $response = $this->postJson('/api/v1/auth/forgot-password', [
        'email' => 'reset@humae.com.mx',
    ]);

    $response->assertOk()->assertJsonPath('success', true);

    Notification::assertSentTo($user, ResetPassword::class);
});

it('responds generically for unknown email (no enumeration)', function (): void {
    Notification::fake();

    $response = $this->postJson('/api/v1/auth/forgot-password', [
        'email' => 'unknown@humae.com.mx',
    ]);

    $response->assertOk()->assertJsonPath('success', true);
    Notification::assertNothingSent();
});

it('resets password with valid token', function (): void {
    $user = User::factory()->create([
        'email' => 'resetme@humae.com.mx',
        'password' => Hash::make('OldPassword123'),
    ]);

    $token = app('auth.password.broker')->createToken($user);

    $response = $this->postJson('/api/v1/auth/reset-password', [
        'token' => $token,
        'email' => 'resetme@humae.com.mx',
        'password' => 'NewPassword123',
        'password_confirmation' => 'NewPassword123',
    ]);

    $response->assertOk()->assertJsonPath('success', true);

    $user->refresh();
    expect(Hash::check('NewPassword123', $user->password))->toBeTrue();
});
