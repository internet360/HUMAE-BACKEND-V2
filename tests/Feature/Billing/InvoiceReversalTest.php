<?php

declare(strict_types=1);

use App\Enums\InvoiceRequestStatus;
use App\Enums\PaymentStatus;
use App\Helpers\StripeClient;
use App\Models\InvoiceRequest;
use App\Models\InvoiceRequestPayment;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\BillingAlertNotification;
use App\Services\InvoiceRequestService;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event as EventFacade;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;
use Stripe\Charge;
use Stripe\Dispute;
use Stripe\Event;

const REVERSAL_UUID = '6f1c2b0e-4a5d-4c3b-9e8f-1a2b3c4d5e6f';

beforeEach(function (): void {
    config(['billing.email' => 'billing@example.test']);
});

/** One fake per test: the webhook controller is cached on the route, so only the event is swapped. */
function invoiceReversalSend(string $id, string $type, object $object): void
{
    if (! app()->bound('invoice.reversal.fake')) {
        app()->instance('invoice.reversal.fake', new class('sk_test_dummy', 'whsec_dummy') extends StripeClient
        {
            public ?Event $event = null;

            public function constructWebhookEvent(string $payload, string $signature): Event
            {
                return $this->event ?? throw new RuntimeException('No event prepared.');
            }
        });
    }

    $fake = app('invoice.reversal.fake');
    $fake->event = Event::constructFrom([
        'id' => $id,
        'type' => $type,
        'livemode' => false,
        'created' => time(),
        'data' => ['object' => $object],
    ]);
    app()->instance(StripeClient::class, $fake);

    test()->postJson('/api/v1/webhooks/stripe', [], ['Stripe-Signature' => 't=0,v1=fake'])->assertOk();
}

function invoiceReversalCharge(int $refundedCents): Charge
{
    return Charge::constructFrom([
        'id' => 'ch_inv',
        'payment_intent' => 'pi_inv',
        'amount' => 49900,
        'amount_refunded' => $refundedCents,
        'metadata' => ['app' => 'humae'],
    ]);
}

function invoiceReversalDispute(string $status): Dispute
{
    return Dispute::constructFrom([
        'id' => 'dp_inv',
        'charge' => 'ch_inv',
        'payment_intent' => 'pi_inv',
        'status' => $status,
        'amount' => 49900,
        'metadata' => ['app' => 'humae'],
    ]);
}

/** @return array{0: Payment, 1: InvoiceRequest} */
function invoicedPayment(InvoiceRequestStatus $status, bool $claimHeld = true): array
{
    $user = User::factory()->create();
    $payment = Payment::factory()->create([
        'user_id' => $user->id,
        'amount' => 499,
        'net_amount' => 499,
        'status' => PaymentStatus::Succeeded,
        'stripe_payment_intent_id' => 'pi_inv',
        'stripe_charge_id' => 'ch_inv',
        'paid_at' => now()->subDay(),
    ]);
    $request = InvoiceRequest::factory()->create(['user_id' => $user->id, 'status' => $status]);

    if ($status === InvoiceRequestStatus::Issued || $status === InvoiceRequestStatus::CancellationPending) {
        $request->forceFill(['cfdi_uuid' => REVERSAL_UUID, 'issued_at' => now()])->save();
    }

    InvoiceRequestPayment::create([
        'invoice_request_id' => $request->id,
        'payment_id' => $payment->id,
        'amount' => 499,
        'paid_at' => $payment->paid_at,
        'claimed_payment_id' => $claimHeld ? $payment->id : null,
    ]);

    return [$payment, $request];
}

it('moves an issued request to cancellation pending on a full refund and tells billing', function (): void {
    Notification::fake();
    [, $request] = invoicedPayment(InvoiceRequestStatus::Issued);

    invoiceReversalSend('evt_ir_1', 'charge.refunded', invoiceReversalCharge(49900));

    expect($request->fresh()->status)->toBe(InvoiceRequestStatus::CancellationPending);
    Notification::assertSentOnDemand(
        BillingAlertNotification::class,
        fn (BillingAlertNotification $n): bool => str_contains($n->body, "#{$request->id}")
            && str_contains($n->body, REVERSAL_UUID)
            && str_contains($n->body, 'SAT')
            && ! str_contains($n->body, 'XXXX010101AAA'),
    );

    $log = Activity::where('log_name', 'invoice-requests')->latest('id')->first();
    expect($log)->not->toBeNull()
        ->and($log->causer_id)->toBeNull()
        ->and($log->properties['from'])->toBe('issued')
        ->and($log->properties['to'])->toBe('cancellation_pending')
        ->and($log->properties['reason'])->toBe('refunded')
        ->and(json_encode($log->properties))->not->toContain('XXXX010101AAA');
});

it('moves an issued request to cancellation pending when a dispute is lost', function (): void {
    Notification::fake();
    [, $request] = invoicedPayment(InvoiceRequestStatus::Issued);

    invoiceReversalSend('evt_ir_d', 'charge.dispute.closed', invoiceReversalDispute('lost'));

    expect($request->fresh()->status)->toBe(InvoiceRequestStatus::CancellationPending);
    Notification::assertSentOnDemand(
        BillingAlertNotification::class,
        fn (BillingAlertNotification $n): bool => str_contains($n->body, REVERSAL_UUID),
    );
});

it('rejects an unissued request, frees its claim and keeps the payment out of the eligible list', function (InvoiceRequestStatus $status): void {
    Notification::fake();
    [$payment, $request] = invoicedPayment($status);

    invoiceReversalSend('evt_ir_open', 'charge.refunded', invoiceReversalCharge(49900));

    $request->refresh();
    expect($request->status)->toBe(InvoiceRequestStatus::Rejected)
        ->and($request->rejection_reason)->toBe('Pago reembolsado')
        ->and($request->payments()->first()->claimed_payment_id)->toBeNull()
        ->and(app(InvoiceRequestService::class)->eligibleQuery($payment->user)->whereKey($payment->id)->exists())->toBeFalse();

    Notification::assertSentOnDemand(
        BillingAlertNotification::class,
        fn (BillingAlertNotification $n): bool => str_contains($n->body, "#{$request->id}") && str_contains($n->body, 'rejected'),
    );
    expect(Activity::where('log_name', 'invoice-requests')->latest('id')->first()->properties['to'])->toBe('rejected');
})->with([
    'requested' => [InvoiceRequestStatus::Requested],
    'in progress' => [InvoiceRequestStatus::InProgress],
]);

it('uses a dispute specific reason when a lost dispute rejects an unissued request', function (): void {
    Notification::fake();
    [, $request] = invoicedPayment(InvoiceRequestStatus::InProgress);

    invoiceReversalSend('evt_ir_dr', 'charge.dispute.closed', invoiceReversalDispute('lost'));

    expect($request->fresh()->rejection_reason)->toBe('Disputa de pago perdida');
});

it('does not transition or notify twice when the reversal is replayed', function (): void {
    Notification::fake();
    [, $request] = invoicedPayment(InvoiceRequestStatus::Issued);

    invoiceReversalSend('evt_ir_r1', 'charge.refunded', invoiceReversalCharge(49900));
    invoiceReversalSend('evt_ir_r1', 'charge.refunded', invoiceReversalCharge(49900));
    invoiceReversalSend('evt_ir_r2', 'charge.refunded', invoiceReversalCharge(49900));

    expect($request->fresh()->status)->toBe(InvoiceRequestStatus::CancellationPending)
        ->and(Activity::where('log_name', 'invoice-requests')->count())->toBe(1);
    // One generic reversal alert plus one CFDI alert; never more.
    Notification::assertSentOnDemandTimes(BillingAlertNotification::class, 2);
});

it('leaves the request untouched and mentions the invoice on a partial refund', function (): void {
    Notification::fake();
    [, $request] = invoicedPayment(InvoiceRequestStatus::Issued);

    invoiceReversalSend('evt_ir_p', 'charge.refunded', invoiceReversalCharge(10000));

    expect($request->fresh()->status)->toBe(InvoiceRequestStatus::Issued);
    Notification::assertSentOnDemandTimes(BillingAlertNotification::class, 1);
    Notification::assertSentOnDemand(
        BillingAlertNotification::class,
        fn (BillingAlertNotification $n): bool => str_contains($n->body, "#{$request->id}")
            && str_contains($n->body, REVERSAL_UUID)
            && ! str_contains($n->body, 'XXXX010101AAA'),
    );
});

it('does not mention an invoice on a partial refund of a payment without one', function (): void {
    Notification::fake();
    Payment::factory()->create([
        'amount' => 499,
        'net_amount' => 499,
        'status' => PaymentStatus::Succeeded,
        'stripe_payment_intent_id' => 'pi_inv',
        'stripe_charge_id' => 'ch_inv',
    ]);

    invoiceReversalSend('evt_ir_np', 'charge.refunded', invoiceReversalCharge(10000));

    Notification::assertSentOnDemand(
        BillingAlertNotification::class,
        fn (BillingAlertNotification $n): bool => ! str_contains($n->body, 'Invoice request'),
    );
});

it('answers 200, logs and alerts billing when the request cannot be transitioned', function (): void {
    Notification::fake();
    $logged = [];
    EventFacade::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged): void {
        $logged[] = $e;
    });
    // Inconsistent data: a cancelled request that still holds its claim.
    [$payment, $request] = invoicedPayment(InvoiceRequestStatus::Cancelled);

    invoiceReversalSend('evt_ir_bad', 'charge.refunded', invoiceReversalCharge(49900));

    expect($request->fresh()->status)->toBe(InvoiceRequestStatus::Cancelled)
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Refunded)
        ->and(Activity::where('log_name', 'invoice-requests')->count())->toBe(0);

    $errors = array_filter($logged, fn (MessageLogged $e): bool => $e->level === 'error' && str_contains($e->message, 'Invoice request'));
    expect($errors)->not->toBeEmpty();
    Notification::assertSentOnDemand(
        BillingAlertNotification::class,
        fn (BillingAlertNotification $n): bool => str_contains($n->body, "#{$request->id}") && str_contains($n->body, 'manual'),
    );
});

it('ignores a request that is already cancellation pending', function (): void {
    Notification::fake();
    [, $request] = invoicedPayment(InvoiceRequestStatus::CancellationPending);

    invoiceReversalSend('evt_ir_cp', 'charge.refunded', invoiceReversalCharge(49900));

    expect($request->fresh()->status)->toBe(InvoiceRequestStatus::CancellationPending)
        ->and(Activity::where('log_name', 'invoice-requests')->count())->toBe(0);
    Notification::assertSentOnDemandTimes(BillingAlertNotification::class, 1);
});

it('does nothing for a reversed payment without an invoice request', function (): void {
    Notification::fake();
    Payment::factory()->create([
        'amount' => 499,
        'net_amount' => 499,
        'status' => PaymentStatus::Succeeded,
        'stripe_payment_intent_id' => 'pi_inv',
        'stripe_charge_id' => 'ch_inv',
    ]);

    invoiceReversalSend('evt_ir_none', 'charge.refunded', invoiceReversalCharge(49900));

    expect(Activity::where('log_name', 'invoice-requests')->count())->toBe(0);
    Notification::assertSentOnDemandTimes(BillingAlertNotification::class, 1);
});
