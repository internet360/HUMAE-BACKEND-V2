<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\InvoiceRequest;
use App\Models\Payment;
use App\Models\User;
use App\Services\InvoiceRequestService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->owner = User::factory()->create()->assignRole(UserRole::Candidate->value);
    $this->request = InvoiceRequest::factory()->create(['user_id' => $this->owner->id]);
});

it('lets the owner and an admin view a request', function (): void {
    $admin = User::factory()->create()->assignRole(UserRole::Admin->value);

    expect($this->owner->can('view', $this->request))->toBeTrue()
        ->and($admin->can('view', $this->request))->toBeTrue();
});

it('denies other candidates, recruiters and companies', function (UserRole $role): void {
    $other = User::factory()->create()->assignRole($role->value);

    expect($other->can('view', $this->request))->toBeFalse();
})->with([UserRole::Candidate, UserRole::Recruiter, UserRole::CompanyUser]);

it('does not cascade-delete fiscal records or payments with the user', function (): void {
    $payment = Payment::factory()->create(['user_id' => $this->owner->id]);
    app(InvoiceRequestService::class)->claim($this->owner, [$payment->id], [
        'rfc' => 'XXXX010101AAA', 'legal_name' => 'X', 'tax_regime' => '612',
        'postal_code' => '06600', 'cfdi_use' => 'G03', 'email' => 'a@b.mx',
    ]);

    expect(fn () => $this->owner->delete())->toThrow(QueryException::class);
    expect(InvoiceRequest::count())->toBe(2)
        ->and(Payment::count())->toBe(1);
});
