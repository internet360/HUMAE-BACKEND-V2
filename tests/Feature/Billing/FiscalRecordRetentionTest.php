<?php

declare(strict_types=1);

use App\Models\InvoiceRequest;
use App\Models\Payment;
use App\Models\User;
use App\Services\InvoiceRequestService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

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

it('refuses to roll back a fiscal migration while invoice requests exist', function (string $file): void {
    InvoiceRequest::factory()->create();
    $migration = require database_path('migrations/'.$file);

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'invoice_requests');
    expect(InvoiceRequest::count())->toBe(1)
        ->and(Schema::hasTable('invoice_requests'))->toBeTrue()
        ->and(Schema::hasTable('invoice_request_payments'))->toBeTrue();
})->with([
    '2026_10_06_120000_create_invoice_requests_tables.php',
    '2026_10_07_120100_add_admin_notes_to_invoice_requests_table.php',
    '2026_10_08_120000_add_cfdi_files_to_invoice_requests_table.php',
]);
