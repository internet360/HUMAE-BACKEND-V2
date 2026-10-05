<?php

declare(strict_types=1);

use App\Enums\CandidateState;
use App\Enums\MembershipStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Events\PaymentReversed;
use App\Helpers\StripeClient;
use App\Jobs\ExpireMembershipsJob;
use App\Models\CandidateProfile;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Payment;
use App\Models\SalaryCurrency;
use App\Models\StripeWebhookEvent;
use App\Models\User;
use App\Notifications\BillingAlertNotification;
use App\Services\MembershipService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Event as EventFacade;
use Illuminate\Support\Facades\Notification;
use Stripe\Charge;
use Stripe\Checkout\Session as CheckoutSession;
use Stripe\Dispute;
use Stripe\Event;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $mxn = SalaryCurrency::factory()->create(['code' => 'MXN']);
    MembershipPlan::factory()->create([
        'code' => 'candidate_6m',
        'price' => 499,
        'duration_days' => 180,
        'salary_currency_id' => $mxn->id,
        'is_active' => true,
    ]);
    config(['billing.email' => 'billing@example.test']);
});

/**
 * Offline Stripe fake. The webhook controller is cached on the route within a test,
 * so the SAME fake instance is kept and only the event it returns is swapped.
 */
function reversalClient(Event $event): StripeClient
{
    if (! app()->bound('reversal.fake')) {
        $fake = new class('sk_test_dummy', 'whsec_dummy') extends StripeClient
        {
            public ?Event $event = null;

            public function constructWebhookEvent(string $payload, string $signature): Event
            {
                return $this->event ?? throw new RuntimeException('No event prepared.');
            }
        };
        app()->instance('reversal.fake', $fake);
    }

    $fake = app('reversal.fake');
    $fake->event = $event;
    app()->instance(StripeClient::class, $fake);

    return $fake;
}

function sendStripeEvent(string $id, string $type, object $object, ?int $created = null): void
{
    reversalClient(Event::constructFrom([
        'id' => $id,
        'type' => $type,
        'livemode' => false,
        'created' => $created ?? time(),
        'data' => ['object' => $object],
    ]));

    test()->postJson('/api/v1/webhooks/stripe', [], ['Stripe-Signature' => 't=0,v1=fake'])->assertOk();
}

/** @param  array<string, string>  $metadata */
function refundCharge(int $refundedCents, string $pi = 'pi_rev', string $charge = 'ch_rev', array $metadata = ['app' => 'humae']): Charge
{
    return Charge::constructFrom([
        'id' => $charge,
        'payment_intent' => $pi,
        'amount' => 49900,
        'amount_refunded' => $refundedCents,
        'metadata' => $metadata,
    ]);
}

/** @param  array<string, string>  $metadata */
function disputeFor(string $status, string $pi = 'pi_rev', string $charge = 'ch_rev', array $metadata = ['app' => 'humae']): Dispute
{
    return Dispute::constructFrom([
        'id' => 'dp_rev',
        'charge' => $charge,
        'payment_intent' => $pi,
        'status' => $status,
        'metadata' => $metadata,
    ]);
}

/** A paid candidate with an active membership. */
function paidCandidate(CandidateState $state = CandidateState::Activo, ?string $charge = 'ch_rev'): Payment
{
    $plan = MembershipPlan::where('code', 'candidate_6m')->first();
    $user = User::factory()->create();
    $user->assignRole(UserRole::Candidate->value);
    CandidateProfile::factory()->create(['user_id' => $user->id, 'state' => $state]);

    $membership = Membership::factory()->create([
        'user_id' => $user->id,
        'membership_plan_id' => $plan->id,
        'status' => MembershipStatus::Active,
        'started_at' => now()->subDays(10),
        'expires_at' => now()->addDays(170),
    ]);

    return Payment::factory()->create([
        'user_id' => $user->id,
        'membership_id' => $membership->id,
        'membership_plan_id' => $plan->id,
        'salary_currency_id' => $plan->salary_currency_id,
        'amount' => 499,
        'net_amount' => 499,
        'status' => PaymentStatus::Succeeded,
        'stripe_session_id' => 'cs_rev',
        'stripe_payment_intent_id' => 'pi_rev',
        'stripe_charge_id' => $charge,
    ]);
}

it('revokes access on a full refund', function (): void {
    Notification::fake();
    EventFacade::fake([PaymentReversed::class]);
    $payment = paidCandidate();

    sendStripeEvent('evt_full', 'charge.refunded', refundCharge(49900));

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Refunded)
        ->and($payment->refunded_at)->not->toBeNull()
        ->and((float) $payment->refund_amount)->toBe(499.0)
        ->and($payment->membership->status)->toBe(MembershipStatus::Refunded)
        ->and($payment->membership->cancelled_at)->not->toBeNull()
        ->and(CandidateProfile::where('user_id', $payment->user_id)->first()->state)
        ->toBe(CandidateState::MembresiaVencida);

    EventFacade::assertDispatchedTimes(PaymentReversed::class, 1);
    Notification::assertSentOnDemand(BillingAlertNotification::class);
});

it('does not repeat the revocation when the refund is replayed under a new event id', function (): void {
    Notification::fake();
    EventFacade::fake([PaymentReversed::class]);
    $payment = paidCandidate();

    sendStripeEvent('evt_full_a', 'charge.refunded', refundCharge(49900));
    $first = $payment->fresh()->refunded_at;
    sendStripeEvent('evt_full_b', 'charge.refunded', refundCharge(49900));

    expect($payment->fresh()->refunded_at->equalTo($first))->toBeTrue();
    EventFacade::assertDispatchedTimes(PaymentReversed::class, 1);
    Notification::assertSentOnDemandTimes(BillingAlertNotification::class, 1);
});

it('keeps access on a partial refund and flags billing', function (): void {
    Notification::fake();
    $payment = paidCandidate();

    sendStripeEvent('evt_part', 'charge.refunded', refundCharge(10000));

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Succeeded)
        ->and((float) $payment->refund_amount)->toBe(100.0)
        ->and($payment->metadata['refund_review'] ?? null)->toBeTrue()
        ->and($payment->membership->status)->toBe(MembershipStatus::Active)
        ->and(CandidateProfile::where('user_id', $payment->user_id)->first()->state)->toBe(CandidateState::Activo);

    Notification::assertSentOnDemandTimes(BillingAlertNotification::class, 1);
});

it('revokes when a partial refund later reaches the full amount', function (): void {
    Notification::fake();
    $payment = paidCandidate();

    sendStripeEvent('evt_p1', 'charge.refunded', refundCharge(10000));
    sendStripeEvent('evt_p2', 'charge.refunded', refundCharge(49900));

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Refunded)
        ->and($payment->membership->status)->toBe(MembershipStatus::Refunded);
});

it('only flags billing when a dispute opens', function (): void {
    Notification::fake();
    $payment = paidCandidate();

    sendStripeEvent('evt_dp_open', 'charge.dispute.created', disputeFor('needs_response'));

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Succeeded)
        ->and($payment->metadata['dispute_status'] ?? null)->toBe('open')
        ->and($payment->membership->status)->toBe(MembershipStatus::Active);
    Notification::assertSentOnDemandTimes(BillingAlertNotification::class, 1);
});

it('revokes access when a dispute is lost', function (): void {
    Notification::fake();
    EventFacade::fake([PaymentReversed::class]);
    $payment = paidCandidate();

    sendStripeEvent('evt_dp_open', 'charge.dispute.created', disputeFor('needs_response'));
    sendStripeEvent('evt_dp_lost', 'charge.dispute.closed', disputeFor('lost'));

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Refunded)
        ->and($payment->refund_reason)->toBe('dispute_lost')
        ->and($payment->membership->status)->toBe(MembershipStatus::Refunded);
    EventFacade::assertDispatchedTimes(PaymentReversed::class, 1);
});

it('leaves access untouched and clears the flag when a dispute is won', function (): void {
    Notification::fake();
    $payment = paidCandidate();

    sendStripeEvent('evt_dp_open', 'charge.dispute.created', disputeFor('needs_response'));
    sendStripeEvent('evt_dp_won', 'charge.dispute.closed', disputeFor('won'));

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Succeeded)
        ->and($payment->metadata)->not->toHaveKey('dispute_status')
        ->and($payment->membership->status)->toBe(MembershipStatus::Active);
});

it('keeps an advanced pipeline state when revoking', function (): void {
    Notification::fake();
    $payment = paidCandidate(CandidateState::EnProceso);

    sendStripeEvent('evt_adv', 'charge.refunded', refundCharge(49900));

    expect(CandidateProfile::where('user_id', $payment->user_id)->first()->state)
        ->toBe(CandidateState::EnProceso);
    expect($payment->fresh()->status)->toBe(PaymentStatus::Refunded);
});

it('keeps the profile active when the user holds another active membership', function (): void {
    Notification::fake();
    $payment = paidCandidate();
    Membership::factory()->create([
        'user_id' => $payment->user_id,
        'membership_plan_id' => $payment->membership_plan_id,
        'status' => MembershipStatus::Active,
        'started_at' => now(),
        'expires_at' => now()->addDays(100),
    ]);

    sendStripeEvent('evt_other', 'charge.refunded', refundCharge(49900));

    expect(CandidateProfile::where('user_id', $payment->user_id)->first()->state)->toBe(CandidateState::Activo);
});

it('does not let ExpireMembershipsJob touch a refunded membership', function (): void {
    Notification::fake();
    $payment = paidCandidate();
    sendStripeEvent('evt_job', 'charge.refunded', refundCharge(49900));
    $payment->membership->forceFill(['expires_at' => now()->subDay()])->save();

    (new ExpireMembershipsJob)->handle(app(MembershipService::class));

    expect($payment->membership->fresh()->status)->toBe(MembershipStatus::Refunded);
});

it('matches by charge id when the payment intent is unknown and backfills the intent link', function (): void {
    Notification::fake();
    $payment = paidCandidate();
    $payment->update(['stripe_payment_intent_id' => null]);

    sendStripeEvent('evt_bycharge', 'charge.refunded', refundCharge(49900, pi: 'pi_other'));

    expect($payment->fresh()->status)->toBe(PaymentStatus::Refunded)
        ->and($payment->fresh()->stripe_payment_intent_id)->toBe('pi_other');
});

it('backfills the charge id when matching by payment intent', function (): void {
    Notification::fake();
    $payment = paidCandidate(charge: null);

    sendStripeEvent('evt_backfill', 'charge.refunded', refundCharge(10000));

    expect($payment->fresh()->stripe_charge_id)->toBe('ch_rev');
});

it('fails the pending payment on async payment failure and on expiry without granting access', function (string $type): void {
    $plan = MembershipPlan::where('code', 'candidate_6m')->first();
    $payment = Payment::factory()->create([
        'user_id' => User::factory()->create()->id,
        'membership_plan_id' => $plan->id,
        'salary_currency_id' => $plan->salary_currency_id,
        'amount' => 499,
        'net_amount' => 499,
        'status' => PaymentStatus::Pending,
        'paid_at' => null,
        'stripe_session_id' => 'cs_async',
    ]);

    sendStripeEvent('evt_'.$type, $type, CheckoutSession::constructFrom(['id' => 'cs_async']));

    expect($payment->fresh()->status)->toBe(PaymentStatus::Failed)
        ->and(Membership::count())->toBe(0);
})->with(['checkout.session.async_payment_failed', 'checkout.session.expired']);

it('never downgrades a succeeded payment on a late expiry event', function (): void {
    $payment = paidCandidate();

    sendStripeEvent('evt_late', 'checkout.session.expired', CheckoutSession::constructFrom(['id' => 'cs_rev']));

    expect($payment->fresh()->status)->toBe(PaymentStatus::Succeeded);
});

it('retries a refund that arrives before the completion and converges afterwards', function (): void {
    Notification::fake();
    $plan = MembershipPlan::where('code', 'candidate_6m')->first();
    $user = User::factory()->create();
    $user->assignRole(UserRole::Candidate->value);
    $payment = Payment::factory()->create([
        'user_id' => $user->id,
        'membership_plan_id' => $plan->id,
        'salary_currency_id' => $plan->salary_currency_id,
        'amount' => 499,
        'net_amount' => 499,
        'status' => PaymentStatus::Pending,
        'paid_at' => null,
        'stripe_session_id' => 'cs_ooo',
    ]);

    $refund = Event::constructFrom([
        'id' => 'evt_ooo_refund',
        'type' => 'charge.refunded',
        'livemode' => false,
        'data' => ['object' => refundCharge(49900, 'pi_ooo', 'ch_ooo')],
    ]);
    reversalClient($refund);

    // Refund first: nothing to match yet, so 500 and no dedup row (Stripe retries).
    test()->postJson('/api/v1/webhooks/stripe', [], ['Stripe-Signature' => 't=0,v1=fake'])->assertStatus(500);
    expect(StripeWebhookEvent::where('event_id', 'evt_ooo_refund')->exists())->toBeFalse();

    sendStripeEvent('evt_ooo_done', 'checkout.session.completed', CheckoutSession::constructFrom([
        'id' => 'cs_ooo',
        'customer' => 'cus_ooo',
        'payment_status' => 'paid',
        'payment_intent' => 'pi_ooo',
    ]));

    // The retried refund now converges to refunded with no active access.
    reversalClient($refund);
    test()->postJson('/api/v1/webhooks/stripe', [], ['Stripe-Signature' => 't=0,v1=fake'])->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Refunded)
        ->and($payment->fresh()->membership->status)->toBe(MembershipStatus::Refunded);
});

it('queues the billing alert after commit', function (): void {
    $notification = new BillingAlertNotification('Subject', 'Body');

    expect($notification)->toBeInstanceOf(ShouldQueue::class)
        ->and($notification->afterCommit)->toBeTrue();
});

it('does not fail the webhook when no billing address is configured', function (): void {
    config(['billing.email' => null]);
    Notification::fake();
    $payment = paidCandidate();

    sendStripeEvent('evt_nomail', 'charge.refunded', refundCharge(49900));

    expect($payment->fresh()->status)->toBe(PaymentStatus::Refunded);
    Notification::assertNothingSent();
});

it('acks reversal events for charges this app never created without retrying', function (string $type): void {
    Notification::fake();
    $foreign = $type === 'charge.refunded'
        ? refundCharge(49900, 'pi_foreign', 'ch_foreign', [])
        : disputeFor('lost', 'pi_foreign', 'ch_foreign', ['app' => 'other']);

    sendStripeEvent('evt_foreign_'.$type, $type, $foreign);

    expect(StripeWebhookEvent::where('event_id', 'evt_foreign_'.$type)->exists())->toBeTrue();
    Notification::assertNothingSent();
})->with(['charge.refunded', 'charge.dispute.closed', 'charge.dispute.created']);

it('keeps retrying our own unmatched charge while the event is inside the retry window', function (): void {
    Notification::fake();
    config(['billing.reversal_retry_window_hours' => 24]);

    reversalClient(Event::constructFrom([
        'id' => 'evt_young',
        'type' => 'charge.refunded',
        'livemode' => false,
        'created' => time() - 3600,
        'data' => ['object' => refundCharge(49900, 'pi_young', 'ch_young')],
    ]));

    test()->postJson('/api/v1/webhooks/stripe', [], ['Stripe-Signature' => 't=0,v1=fake'])->assertStatus(500);
    expect(StripeWebhookEvent::where('event_id', 'evt_young')->exists())->toBeFalse();
    Notification::assertNothingSent();
});

it('gives up on our own unmatched charge after the retry window, alerting billing', function (): void {
    Notification::fake();
    config(['billing.reversal_retry_window_hours' => 24]);

    sendStripeEvent('evt_old', 'charge.refunded', refundCharge(49900, 'pi_old', 'ch_old'), time() - 25 * 3600);

    expect(StripeWebhookEvent::where('event_id', 'evt_old')->exists())->toBeTrue();
    Notification::assertSentOnDemandTimes(BillingAlertNotification::class, 1);
});

it('honours a configured retry window', function (): void {
    Notification::fake();
    config(['billing.reversal_retry_window_hours' => 1]);

    sendStripeEvent('evt_short', 'charge.refunded', refundCharge(49900, 'pi_short', 'ch_short'), time() - 2 * 3600);

    Notification::assertSentOnDemandTimes(BillingAlertNotification::class, 1);
});
