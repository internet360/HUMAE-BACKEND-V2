<?php

declare(strict_types=1);

use App\Enums\InvoiceRequestStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\InvalidInvoiceRequestTransitionException;
use App\Exceptions\PaymentNotEligibleException;
use App\Models\InvoiceRequest;
use App\Models\Payment;
use App\Models\User;
use App\Services\InvoiceRequestService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

const FISCAL = [
    'rfc' => 'XXXX010101AAA',
    'legal_name' => 'Persona de Prueba',
    'tax_regime' => '612',
    'postal_code' => '06600',
    'cfdi_use' => 'G03',
    'email' => 'fiscal@example.com',
];

beforeEach(function (): void {
    // Wednesday 14 Oct 2026, noon in Mexico City.
    $this->travelTo(Carbon::parse('2026-10-14 12:00:00', 'America/Mexico_City'));
    $this->user = User::factory()->create();
    $this->service = app(InvoiceRequestService::class);
});

function paidAt(User $user, string $localTime, array $attributes = []): Payment
{
    return Payment::factory()->create([
        'user_id' => $user->id,
        'paid_at' => Carbon::parse($localTime, 'America/Mexico_City')->utc(),
        ...$attributes,
    ]);
}

function eligibleIds(): array
{
    return test()->service->eligiblePayments(test()->user)->pluck('id')->all();
}

it('lists succeeded unclaimed payments of the current month', function (): void {
    $payment = paidAt($this->user, '2026-10-02 09:00');
    paidAt(User::factory()->create(), '2026-10-02 09:00');

    expect(eligibleIds())->toBe([$payment->id]);
});

it('keeps a payment at 23:30 on the last day of the month inside that month', function (): void {
    // 2026-10-31 23:30 Mexico City is 2026-11-01 05:30 UTC.
    $payment = paidAt($this->user, '2026-10-31 23:30');
    expect($payment->paid_at->utc()->toDateTimeString())->toBe('2026-11-01 05:30:00');

    $this->travelTo(Carbon::parse('2026-10-31 23:45:00', 'America/Mexico_City'));
    expect(eligibleIds())->toBe([$payment->id]);

    // 2026-11-01 00:15 Mexico City: October is closed, the payment expires.
    $this->travelTo(Carbon::parse('2026-11-01 00:15:00', 'America/Mexico_City'));
    expect(eligibleIds())->toBe([]);
});

it('treats the first instant of the month in Mexico City as inside the window', function (): void {
    $payment = paidAt($this->user, '2026-10-01 00:00');
    $before = paidAt($this->user, '2026-09-30 23:59');

    expect(eligibleIds())->toBe([$payment->id])->and($before->id)->not->toBeIn(eligibleIds());
});

it('excludes pending, failed, refunded and unpaid payments', function (): void {
    paidAt($this->user, '2026-10-02 09:00', ['status' => PaymentStatus::Pending]);
    paidAt($this->user, '2026-10-02 09:00', ['status' => PaymentStatus::Failed]);
    paidAt($this->user, '2026-10-02 09:00', ['status' => PaymentStatus::Refunded, 'refunded_at' => now()]);
    paidAt($this->user, '2026-10-02 09:00', ['refunded_at' => now()]);
    Payment::factory()->create(['user_id' => $this->user->id, 'paid_at' => null]);

    expect(eligibleIds())->toBe([]);
});

it('excludes payments with an active or lost dispute but keeps closed favourable ones', function (): void {
    paidAt($this->user, '2026-10-02 09:00', ['metadata' => ['dispute_status' => 'open']]);
    paidAt($this->user, '2026-10-02 09:00', ['metadata' => ['dispute_status' => 'lost']]);
    $won = paidAt($this->user, '2026-10-02 09:00', ['metadata' => ['dispute_status' => 'won']]);
    $noFlag = paidAt($this->user, '2026-10-02 09:00', ['metadata' => ['other' => true]]);

    expect(eligibleIds())->toEqualCanonicalizing([$won->id, $noFlag->id]);
});

it('excludes partially refunded payments so billing handles them manually at the net amount', function (): void {
    paidAt($this->user, '2026-10-02 09:00', ['refund_amount' => 100]);
    paidAt($this->user, '2026-10-02 09:00', ['metadata' => ['refund_review' => true]]);
    $zero = paidAt($this->user, '2026-10-02 09:00', ['refund_amount' => 0]);
    $clean = paidAt($this->user, '2026-10-02 09:00');

    expect(eligibleIds())->toEqualCanonicalizing([$zero->id, $clean->id]);
});

it('excludes every unresolved dispute status', function (string $status): void {
    paidAt($this->user, '2026-10-02 09:00', ['metadata' => ['dispute_status' => $status]]);

    expect(eligibleIds())->toBe([]);
})->with([
    'open',
    'lost',
    'needs_response',
    'under_review',
    'warning_needs_response',
    'warning_under_review',
]);

it('keeps payments with every favourable dispute outcome', function (string $status): void {
    $payment = paidAt($this->user, '2026-10-02 09:00', ['metadata' => ['dispute_status' => $status]]);

    expect(eligibleIds())->toBe([$payment->id]);
})->with(['won', 'warning_closed', 'charge_refunded']);

it('treats a payment with null or missing metadata as eligible', function (): void {
    $nullMetadata = paidAt($this->user, '2026-10-02 09:00', ['metadata' => null]);
    $nullStatus = paidAt($this->user, '2026-10-02 09:00', ['metadata' => ['dispute_status' => null]]);

    expect(eligibleIds())->toEqualCanonicalizing([$nullMetadata->id, $nullStatus->id]);
});

it('claims eligible payments with a snapshot and hides them from the list', function (): void {
    $payment = paidAt($this->user, '2026-10-02 09:00', ['amount' => '499.00']);

    $request = $this->service->claim($this->user, [$payment->id], FISCAL);

    expect($request->status)->toBe(InvoiceRequestStatus::Requested)
        ->and($request->user_id)->toBe($this->user->id)
        ->and($request->payments)->toHaveCount(1)
        ->and($request->payments[0]->claimed_payment_id)->toBe($payment->id)
        ->and($request->payments[0]->amount)->toBe('499.00')
        ->and(eligibleIds())->toBe([]);
});

it('rejects a second claim while the first request is active', function (): void {
    $payment = paidAt($this->user, '2026-10-02 09:00');
    $this->service->claim($this->user, [$payment->id], FISCAL);

    $claim = fn () => $this->service->claim($this->user, [$payment->id], FISCAL);

    expect($claim)->toThrow(PaymentNotEligibleException::class);
    expect(InvoiceRequest::count())->toBe(1);
});

it('frees the claim when the request is rejected or cancelled', function (InvoiceRequestStatus $final): void {
    $payment = paidAt($this->user, '2026-10-02 09:00');
    $request = $this->service->claim($this->user, [$payment->id], FISCAL);
    if ($final === InvoiceRequestStatus::Cancelled) {
        // Cancelled is only reachable through cancellation_pending.
        $request->update(['status' => InvoiceRequestStatus::CancellationPending]);
    }

    $this->service->releaseClaims($request, $final);

    expect($request->fresh()->status)->toBe($final)
        ->and(eligibleIds())->toBe([$payment->id]);

    $again = $this->service->claim($this->user, [$payment->id], FISCAL);
    expect($again->id)->not->toBe($request->id);
})->with([InvoiceRequestStatus::Rejected, InvoiceRequestStatus::Cancelled]);

it('refuses to release a claim into a non-releasing status', function (): void {
    $payment = paidAt($this->user, '2026-10-02 09:00');
    $request = $this->service->claim($this->user, [$payment->id], FISCAL);

    $this->service->releaseClaims($request, InvoiceRequestStatus::Issued);
})->throws(InvalidArgumentException::class);

it('refuses to release the claims of an issued request', function (): void {
    $payment = paidAt($this->user, '2026-10-02 09:00');
    $request = $this->service->claim($this->user, [$payment->id], FISCAL);
    $request->update(['status' => InvoiceRequestStatus::Issued]);

    expect(fn () => $this->service->releaseClaims($request, InvoiceRequestStatus::Rejected))
        ->toThrow(InvalidInvoiceRequestTransitionException::class);

    expect($request->fresh()->status)->toBe(InvoiceRequestStatus::Issued)
        ->and($request->payments()->first()->claimed_payment_id)->toBe($payment->id)
        ->and(eligibleIds())->toBe([]);
});

it('validates the transition against the persisted status, not the stale instance', function (): void {
    $payment = paidAt($this->user, '2026-10-02 09:00');
    $stale = $this->service->claim($this->user, [$payment->id], FISCAL);
    InvoiceRequest::whereKey($stale->id)->update(['status' => InvoiceRequestStatus::Issued]);

    expect(fn () => $this->service->releaseClaims($stale, InvoiceRequestStatus::Rejected))
        ->toThrow(InvalidInvoiceRequestTransitionException::class);
    expect($stale->payments()->first()->claimed_payment_id)->toBe($payment->id);
});

it('enforces claim uniqueness in the database too', function (): void {
    $payment = paidAt($this->user, '2026-10-02 09:00');
    $first = $this->service->claim($this->user, [$payment->id], FISCAL);

    $first->payments()->create([
        'payment_id' => $payment->id,
        'claimed_payment_id' => $payment->id,
        'amount' => '1.00',
        'paid_at' => now(),
    ]);
})->throws(UniqueConstraintViolationException::class);

it('reports why a payment cannot be claimed', function (string $case, string $reason): void {
    $other = User::factory()->create();
    $payment = match ($case) {
        'foreign' => paidAt($other, '2026-10-02 09:00'),
        'refunded' => paidAt($this->user, '2026-10-02 09:00', ['status' => PaymentStatus::Refunded, 'refunded_at' => now()]),
        'deadline' => paidAt($this->user, '2026-09-20 09:00'),
        'dispute' => paidAt($this->user, '2026-10-02 09:00', ['metadata' => ['dispute_status' => 'open']]),
    };

    try {
        $this->service->claim($this->user, [$payment->id], FISCAL);
        $this->fail('Expected PaymentNotEligibleException');
    } catch (PaymentNotEligibleException $e) {
        expect($e->reason)->toBe($reason);
    }
    expect(InvoiceRequest::count())->toBe(0);
})->with([
    ['foreign', 'not_found'],
    ['refunded', 'not_eligible'],
    ['dispute', 'not_eligible'],
    ['deadline', 'deadline'],
]);

it('claims several payments atomically or none', function (): void {
    $good = paidAt($this->user, '2026-10-02 09:00');
    $old = paidAt($this->user, '2026-08-02 09:00');

    expect(fn () => $this->service->claim($this->user, [$good->id, $old->id], FISCAL))
        ->toThrow(PaymentNotEligibleException::class);
    expect(eligibleIds())->toBe([$good->id]);
});
