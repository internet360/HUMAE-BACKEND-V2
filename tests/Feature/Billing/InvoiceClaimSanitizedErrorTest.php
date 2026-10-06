<?php

declare(strict_types=1);

use App\Exceptions\InvoiceRequestPersistenceException;
use App\Models\InvoiceRequest;
use App\Models\Payment;
use App\Models\User;
use App\Services\InvoiceRequestService;
use Illuminate\Database\QueryException;

afterEach(fn () => InvoiceRequest::flushEventListeners());

it('rethrows a database failure without SQL bindings so fiscal data never reaches the logs', function (): void {
    $user = User::factory()->create();
    $payment = Payment::factory()->create(['user_id' => $user->id, 'paid_at' => now()->subDay()]);

    InvoiceRequest::creating(function (): void {
        throw new QueryException(
            'mysql',
            'insert into `invoice_requests` (`rfc`, `legal_name`, `email`) values (?, ?, ?)',
            ['XXXX010101AAA', 'Persona Secreta SA', 'secreto@example.com'],
            new PDOException('SQLSTATE[HY000]: General error: 1366 Incorrect string value'),
        );
    });

    $thrown = null;

    try {
        app(InvoiceRequestService::class)->claim($user, [$payment->id], [
            'rfc' => 'XXXX010101AAA', 'legal_name' => 'Persona Secreta SA', 'tax_regime' => '612',
            'postal_code' => '06600', 'cfdi_use' => 'G03', 'email' => 'secreto@example.com',
        ]);
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(InvoiceRequestPersistenceException::class)
        ->and($thrown->getPrevious())->toBeNull()
        ->and($thrown->getMessage())->not->toContain('XXXX010101AAA')->not->toContain('Persona Secreta')->not->toContain('secreto@example.com')->not->toContain('insert into');

    expect(InvoiceRequest::count())->toBe(0);
});
