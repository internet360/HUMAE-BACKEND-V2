<?php

declare(strict_types=1);

use App\Models\InvoiceRequest;
use App\Models\Payment;
use App\Models\User;
use App\Services\InvoiceRequestService;
use Illuminate\Database\QueryException;

it('does not cascade-delete fiscal records or payments with the user', function (): void {
    $user = User::factory()->create();
    $payment = Payment::factory()->create(['user_id' => $user->id]);
    app(InvoiceRequestService::class)->claim($user, [$payment->id], [
        'rfc' => 'XXXX010101AAA', 'legal_name' => 'X', 'tax_regime' => '612',
        'postal_code' => '06600', 'cfdi_use' => 'G03', 'email' => 'a@b.mx',
    ]);

    expect(fn () => $user->delete())->toThrow(QueryException::class);
    expect(InvoiceRequest::count())->toBe(1)->and(Payment::count())->toBe(1);
});
