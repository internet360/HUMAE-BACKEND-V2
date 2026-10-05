<?php

declare(strict_types=1);

use App\Enums\PaymentStatus;
use App\Helpers\StripeClient;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Payment;
use App\Models\SalaryCurrency;
use App\Models\StripeWebhookEvent;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Stripe\Checkout\Session as CheckoutSession;
use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;

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
});

/**
 * Stripe fake for the webhook endpoint. Never reaches the network: the session
 * re-fetch used for charge/receipt enrichment is offline unless a test hands in
 * `$retrieveSession`.
 *
 * @param  (Closure(string, array<string, mixed>): CheckoutSession)|null  $retrieveSession
 */
function fakeWebhookClient(Event $event, ?Closure $retrieveSession = null, bool $badSignature = false): StripeClient
{
    return new class('sk_test_dummy', 'whsec_dummy', $event, $retrieveSession, $badSignature) extends StripeClient
    {
        public function __construct(
            ?string $secretKey,
            ?string $webhookSecret,
            private readonly Event $event,
            private readonly ?Closure $retrieveSession,
            private readonly bool $badSignature,
        ) {
            parent::__construct($secretKey, $webhookSecret);
        }

        public function constructWebhookEvent(string $payload, string $signature): Event
        {
            if ($this->badSignature) {
                throw new SignatureVerificationException('No signatures found matching the expected signature.');
            }

            return $this->event;
        }

        /** @param  array<string, mixed>  $params */
        public function retrieveCheckoutSession(string $sessionId, array $params = []): CheckoutSession
        {
            if ($this->retrieveSession === null) {
                throw new RuntimeException('Stripe is offline in tests.');
            }

            return ($this->retrieveSession)($sessionId, $params);
        }
    };
}

function pendingPayment(string $sessionId): Payment
{
    $plan = MembershipPlan::where('code', 'candidate_6m')->first();

    return Payment::factory()->create([
        'user_id' => User::factory()->create()->id,
        'membership_plan_id' => $plan->id,
        'salary_currency_id' => $plan->salary_currency_id,
        'amount' => 499,
        'net_amount' => 499,
        'status' => PaymentStatus::Pending,
        'stripe_session_id' => $sessionId,
    ]);
}

function completedEvent(string $eventId, string $sessionId, string $paymentIntent): Event
{
    return Event::constructFrom([
        'id' => $eventId,
        'type' => 'checkout.session.completed',
        'livemode' => false,
        'data' => ['object' => CheckoutSession::constructFrom([
            'id' => $sessionId,
            'customer' => 'cus_'.$sessionId,
            'payment_intent' => $paymentIntent,
        ])],
    ]);
}

function postStripeWebhook(): TestResponse
{
    return test()->postJson('/api/v1/webhooks/stripe', [], ['Stripe-Signature' => 't=0,v1=fake']);
}

it('activates the membership on checkout.session.completed', function (): void {
    $user = User::factory()->create();
    $plan = MembershipPlan::where('code', 'candidate_6m')->first();

    $payment = Payment::factory()->create([
        'user_id' => $user->id,
        'membership_plan_id' => $plan->id,
        'salary_currency_id' => $plan->salary_currency_id,
        'amount' => 499,
        'net_amount' => 499,
        'status' => PaymentStatus::Pending,
        'stripe_session_id' => 'cs_test_abc123',
    ]);

    $session = CheckoutSession::constructFrom([
        'id' => 'cs_test_abc123',
        'customer' => 'cus_test_123',
        'payment_intent' => 'pi_test_123',
    ]);

    $event = Event::constructFrom([
        'id' => 'evt_test_1',
        'type' => 'checkout.session.completed',
        'data' => ['object' => $session],
    ]);

    $this->app->instance(StripeClient::class, fakeWebhookClient($event));

    $response = $this->postJson('/api/v1/webhooks/stripe', [], [
        'Stripe-Signature' => 't=0,v1=fake',
    ]);

    $response->assertOk()->assertJsonPath('data.type', 'checkout.session.completed');

    $payment->refresh();
    expect($payment->status->value)->toBe('succeeded');
    expect($payment->membership_id)->not->toBeNull();

    $membership = Membership::where('user_id', $user->id)->first();
    expect($membership)->not->toBeNull()
        ->and($membership->status->value)->toBe('active')
        ->and($membership->expires_at->isAfter(now()->addDays(179)))->toBeTrue();
});

it('ignores unknown event types', function (): void {
    $event = Event::constructFrom([
        'id' => 'evt_test_2',
        'type' => 'invoice.created',
        'data' => ['object' => []],
    ]);

    $this->app->instance(StripeClient::class, fakeWebhookClient($event));

    $response = $this->postJson('/api/v1/webhooks/stripe', [], [
        'Stripe-Signature' => 't=0,v1=fake',
    ]);

    $response->assertOk()->assertJsonPath('data.type', 'invoice.created');

    expect(Membership::count())->toBe(0);
});

it('is idempotent when the same session completes twice', function (): void {
    $user = User::factory()->create();
    $plan = MembershipPlan::where('code', 'candidate_6m')->first();

    Payment::factory()->create([
        'user_id' => $user->id,
        'membership_plan_id' => $plan->id,
        'salary_currency_id' => $plan->salary_currency_id,
        'amount' => 499,
        'net_amount' => 499,
        'status' => PaymentStatus::Pending,
        'stripe_session_id' => 'cs_test_idem',
    ]);

    $session = CheckoutSession::constructFrom([
        'id' => 'cs_test_idem',
        'customer' => 'cus_test_idem',
        'payment_intent' => 'pi_test_idem',
    ]);

    $event = Event::constructFrom([
        'id' => 'evt_idem',
        'type' => 'checkout.session.completed',
        'data' => ['object' => $session],
    ]);

    $this->app->instance(StripeClient::class, fakeWebhookClient($event));

    $this->postJson('/api/v1/webhooks/stripe', [], ['Stripe-Signature' => 't=0,v1=fake'])->assertOk();
    $this->postJson('/api/v1/webhooks/stripe', [], ['Stripe-Signature' => 't=0,v1=fake'])->assertOk();

    expect(Membership::where('user_id', $user->id)->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Event deduplication (stripe-webhook-processing R1, R2, R5)
// ---------------------------------------------------------------------------

it('rejects a bad signature with 400 and changes nothing', function (): void {
    $payment = pendingPayment('cs_test_badsig');
    $event = completedEvent('evt_badsig', 'cs_test_badsig', 'pi_badsig');

    $this->app->instance(StripeClient::class, fakeWebhookClient($event, badSignature: true));

    postStripeWebhook()->assertStatus(400);

    expect($payment->refresh()->status)->toBe(PaymentStatus::Pending)
        ->and(Membership::count())->toBe(0)
        ->and(StripeWebhookEvent::count())->toBe(0);
});

it('records the event id once and ignores a replay even if the payment is pending again', function (): void {
    $payment = pendingPayment('cs_test_replay');
    $event = completedEvent('evt_replay', 'cs_test_replay', 'pi_replay');
    $this->app->instance(StripeClient::class, fakeWebhookClient($event));

    postStripeWebhook()->assertOk();

    $row = StripeWebhookEvent::where('event_id', 'evt_replay')->first();
    expect($row)->not->toBeNull()
        ->and($row->type)->toBe('checkout.session.completed')
        ->and($row->processed_at)->not->toBeNull();

    // Rewind the payment: only the dedup row can stop a second activation now,
    // the status guard in MembershipService would let it through.
    // (query-level update: the in-memory model still believes it is pending)
    Payment::whereKey($payment->id)->update(['status' => 'pending', 'membership_id' => null]);

    postStripeWebhook()
        ->assertOk()
        ->assertJsonPath('message', 'Event already processed.');

    expect(StripeWebhookEvent::where('event_id', 'evt_replay')->count())->toBe(1)
        ->and(Membership::count())->toBe(1)
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Pending);
});

it('answers 500 and stores no event row when the handler throws, then processes the retry', function (): void {
    $event = completedEvent('evt_retry', 'cs_test_retry', 'pi_retry');
    $this->app->instance(StripeClient::class, fakeWebhookClient($event));

    // No payment for the session yet: the handler throws.
    postStripeWebhook()->assertStatus(500);

    expect(StripeWebhookEvent::count())->toBe(0)
        ->and(Membership::count())->toBe(0);

    pendingPayment('cs_test_retry');

    postStripeWebhook()->assertOk();

    expect(StripeWebhookEvent::where('event_id', 'evt_retry')->count())->toBe(1)
        ->and(Membership::count())->toBe(1);
});

it('answers 200 when a concurrent delivery wins the unique insert race', function (): void {
    $payment = pendingPayment('cs_test_race');
    $event = completedEvent('evt_race', 'cs_test_race', 'pi_race');
    $this->app->instance(StripeClient::class, fakeWebhookClient($event));

    // Simulate the other worker: its row lands between our pre-check and our insert.
    StripeWebhookEvent::creating(function (): void {
        DB::table('stripe_webhook_events')->insert([
            'event_id' => 'evt_race',
            'type' => 'checkout.session.completed',
            'livemode' => false,
            'processed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    postStripeWebhook()->assertOk();

    StripeWebhookEvent::flushEventListeners();

    expect(StripeWebhookEvent::where('event_id', 'evt_race')->count())->toBe(1)
        ->and(Membership::count())->toBe(0)
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Pending);
});

it('records unknown event types and still answers 200 without side effects', function (): void {
    $event = Event::constructFrom([
        'id' => 'evt_unknown',
        'type' => 'customer.created',
        'data' => ['object' => []],
    ]);
    $this->app->instance(StripeClient::class, fakeWebhookClient($event));

    postStripeWebhook()->assertOk()->assertJsonPath('data.type', 'customer.created');

    expect(StripeWebhookEvent::where('event_id', 'evt_unknown')->count())->toBe(1)
        ->and(Membership::count())->toBe(0);
});
