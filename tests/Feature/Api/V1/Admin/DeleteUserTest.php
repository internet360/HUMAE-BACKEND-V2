<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\InvoiceRequest;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->admin = User::factory()->create([
        'status' => UserStatus::Active->value,
        'email_verified_at' => now(),
    ]);
    $this->admin->assignRole(UserRole::Admin->value);
    Sanctum::actingAs($this->admin);
});

it('deletes a user without fiscal records', function (): void {
    $user = User::factory()->create();

    $this->deleteJson('/api/v1/admin/users/'.$user->id)->assertNoContent();

    expect(User::find($user->id))->toBeNull();
});

it('refuses with 409 to delete a user that holds an invoice request', function (): void {
    $user = User::factory()->create();
    InvoiceRequest::factory()->create(['user_id' => $user->id]);

    $this->deleteJson('/api/v1/admin/users/'.$user->id)
        ->assertStatus(409)
        ->assertJsonPath('message', 'No se puede eliminar al usuario: tiene solicitudes de factura y los registros fiscales deben conservarse.');

    expect(User::find($user->id))->not->toBeNull()
        ->and(InvoiceRequest::where('user_id', $user->id)->count())->toBe(1);
});
