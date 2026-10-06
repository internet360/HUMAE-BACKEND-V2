<?php

declare(strict_types=1);

use App\Enums\PaymentStatus;
use App\Helpers\StripeClient;
use App\Jobs\EnrichPaymentFromStripeJob;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Payment;
use App\Models\SalaryCurrency;
use App\Models\StripeWebhookEvent;
use App\Models\User;
use App\Notifications\BillingAlertNotification;
use App\Services\MembershipService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Stripe\Checkout\Session as CheckoutSession;
use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;
use Stripe\HttpClient\CurlClient;

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
            public Event $event,
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

/** @param  array<string, string>  $metadata */
function completedEvent(string $eventId, string $sessionId, string $paymentIntent, array $metadata = []): Event
{
    return Event::constructFrom([
        'id' => $eventId,
        'type' => 'checkout.session.completed',
        'livemode' => false,
        'data' => ['object' => CheckoutSession::constructFrom([
            'id' => $sessionId,
            'customer' => 'cus_'.$sessionId,
            'payment_status' => 'paid',
            'payment_intent' => $paymentIntent,
            'metadata' => $metadata,
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
        'payment_status' => 'paid',
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
        'payment_status' => 'paid',
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

it('logs a bad signature at warning with the request ip and never the payload', function (): void {
    Log::spy();
    $this->app->instance(StripeClient::class, fakeWebhookClient(completedEvent('evt_ip', 'cs_ip', 'pi_ip'), badSignature: true));

    $this->postJson('/api/v1/webhooks/stripe', ['secret_payload_marker' => 'XYZ'], ['Stripe-Signature' => 't=0,v1=fake'])
        ->assertStatus(400);

    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context): bool => ($context['ip'] ?? null) !== null
            && ! str_contains(json_encode($context), 'XYZ')
    )->once();
    Log::shouldNotHaveReceived('critical');
});

it('logs critical, with a distinct message, when the webhook secret is not configured', function (): void {
    Log::spy();
    $this->app->instance(StripeClient::class, new StripeClient('sk_test_dummy', ''));

    postStripeWebhook()->assertStatus(400);

    Log::shouldHaveReceived('critical')->withArgs(
        fn (string $message): bool => str_contains($message, 'secret is not configured')
    )->once();
    Log::shouldNotHaveReceived('warning');
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
    $event = completedEvent('evt_retry', 'cs_test_retry', 'pi_retry', ['app' => 'humae']);
    $this->app->instance(StripeClient::class, fakeWebhookClient($event));

    // No payment for the session yet (our own session, so Stripe must retry).
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

// ---------------------------------------------------------------------------
// Re-activation guard (membership-checkout: replayed completion)
// ---------------------------------------------------------------------------

it('never re-activates a payment that is no longer pending', function (string $status): void {
    $payment = pendingPayment('cs_test_guard_'.$status);
    Payment::whereKey($payment->id)->update(['status' => $status]);

    $session = CheckoutSession::constructFrom([
        'id' => 'cs_test_guard_'.$status,
        'customer' => 'cus_guard',
        'payment_intent' => 'pi_guard_'.$status,
        'payment_status' => 'paid',
    ]);

    $returned = app(MembershipService::class)->activateFromCheckoutSession($session);

    expect($returned->status->value)->toBe($status)
        ->and(Membership::count())->toBe(0)
        ->and($payment->refresh()->stripe_payment_intent_id)->toBeNull();
})->with(['refunded', 'failed', 'succeeded']);

// ---------------------------------------------------------------------------
// Charge / receipt enrichment (best effort, after commit)
// ---------------------------------------------------------------------------

function expandedSession(string $sessionId, string $paymentIntent, string $chargeId, string $receiptUrl): CheckoutSession
{
    return CheckoutSession::constructFrom([
        'id' => $sessionId,
        'customer' => 'cus_'.$sessionId,
        'payment_status' => 'paid',
        'payment_intent' => [
            'id' => $paymentIntent,
            'latest_charge' => ['id' => $chargeId, 'receipt_url' => $receiptUrl],
        ],
    ]);
}

it('stores the charge id and receipt url after the dedup transaction commits', function (): void {
    $payment = pendingPayment('cs_test_enrich');
    $event = completedEvent('evt_enrich', 'cs_test_enrich', 'pi_enrich');

    $baseLevel = DB::transactionLevel();
    $levelAtFetch = null;
    $fetchedParams = null;

    $this->app->instance(StripeClient::class, fakeWebhookClient(
        $event,
        function (string $id, array $params) use (&$levelAtFetch, &$fetchedParams): CheckoutSession {
            $levelAtFetch = DB::transactionLevel();
            $fetchedParams = $params;

            return expandedSession($id, 'pi_enrich', 'ch_enrich', 'https://pay.stripe.com/receipts/enrich');
        },
    ));

    postStripeWebhook()->assertOk();

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Succeeded)
        ->and($payment->stripe_charge_id)->toBe('ch_enrich')
        ->and($payment->receipt_url)->toBe('https://pay.stripe.com/receipts/enrich')
        ->and($levelAtFetch)->toBe($baseLevel)
        ->and($fetchedParams)->toBe(['expand' => ['payment_intent.latest_charge']]);
});

it('keeps the activation, answers 200 and queues the enrichment for a retry when Stripe is down', function (): void {
    Queue::fake();
    $payment = pendingPayment('cs_test_enrich_fail');
    $event = completedEvent('evt_enrich_fail', 'cs_test_enrich_fail', 'pi_enrich_fail');
    $this->app->instance(StripeClient::class, fakeWebhookClient(
        $event,
        fn (): CheckoutSession => throw new RuntimeException('Stripe is down.'),
    ));

    postStripeWebhook()->assertOk();

    // The webhook itself never calls Stripe for the enrichment: it only queues it.
    Queue::assertPushed(EnrichPaymentFromStripeJob::class, fn (EnrichPaymentFromStripeJob $job): bool => $job->sessionId === 'cs_test_enrich_fail');

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Succeeded)
        ->and($payment->stripe_charge_id)->toBeNull()
        ->and(Membership::count())->toBe(1);
});

it('does not queue the enrichment for an unpaid or non activating event', function (): void {
    Queue::fake();
    pendingPayment('cs_test_enrich_unpaid');
    $this->app->instance(StripeClient::class, fakeWebhookClient(
        sessionEvent('evt_enrich_unpaid', 'checkout.session.completed', 'cs_test_enrich_unpaid', 'unpaid')
    ));

    postStripeWebhook()->assertOk();

    Queue::assertNothingPushed();
});

it('still answers 200 when queueing the enrichment itself fails', function (): void {
    $payment = pendingPayment('cs_test_enrich_queue_down');
    $this->app->instance(StripeClient::class, fakeWebhookClient(completedEvent('evt_enrich_queue_down', 'cs_test_enrich_queue_down', 'pi_q')));
    $this->mock(Dispatcher::class, function ($mock): void {
        $mock->shouldReceive('dispatch')->andThrow(new RuntimeException('queue down'));
    });
    Log::spy();

    postStripeWebhook()->assertOk();

    Log::shouldHaveReceived('warning')->once();
    expect($payment->fresh()->status)->toBe(PaymentStatus::Succeeded);
});

it('has a retrying, after-commit enrichment job that lets failures bubble up', function (): void {
    $job = new EnrichPaymentFromStripeJob('cs_x');

    expect($job)->toBeInstanceOf(ShouldQueueAfterCommit::class)
        ->and($job->tries)->toBe(3)
        ->and($job->backoff())->toBe([30, 120]);

    $payment = pendingPayment('cs_job_throw');
    $failing = fakeWebhookClient(
        completedEvent('evt_unused', 'cs_job_throw', 'pi'),
        fn (): CheckoutSession => throw new RuntimeException('Stripe is down.'),
    );

    expect(fn () => (new EnrichPaymentFromStripeJob('cs_job_throw'))->handle($failing))
        ->toThrow(RuntimeException::class);
    expect($payment->fresh()->stripe_charge_id)->toBeNull();

    Log::spy();
    (new EnrichPaymentFromStripeJob('cs_job_throw'))->failed(new RuntimeException('Stripe is down.'));
    Log::shouldHaveReceived('warning')->once();
});

it('fills only NULL columns in the enrichment job, so running it twice changes nothing', function (): void {
    $payment = pendingPayment('cs_job_idem');
    $calls = 0;
    $client = fakeWebhookClient(
        completedEvent('evt_unused2', 'cs_job_idem', 'pi'),
        function (string $id) use (&$calls): CheckoutSession {
            $calls++;

            return expandedSession($id, 'pi', 'ch_job', 'https://pay.stripe.com/receipts/job');
        },
    );

    (new EnrichPaymentFromStripeJob('cs_job_idem'))->handle($client);
    (new EnrichPaymentFromStripeJob('cs_job_idem'))->handle($client);

    expect($payment->fresh()->stripe_charge_id)->toBe('ch_job')
        ->and($payment->fresh()->receipt_url)->toBe('https://pay.stripe.com/receipts/job')
        ->and($calls)->toBe(1); // second run sees both columns filled and skips Stripe
});

it('configures the Stripe SDK with explicit timeouts and network retries', function (): void {
    $client = new StripeClient('sk_test_dummy', 'whsec', connectTimeout: 5, timeout: 15, maxNetworkRetries: 2);

    $sdk = (new ReflectionMethod($client, 'sdk'))->invoke($client);

    expect($sdk->getMaxNetworkRetries())->toBe(2)
        ->and(CurlClient::instance()->getConnectTimeout())->toBe(5)
        ->and(CurlClient::instance()->getTimeout())->toBe(15)
        ->and(config('services.stripe.timeout'))->toBe(15)
        ->and(config('services.stripe.connect_timeout'))->toBe(5)
        ->and(config('services.stripe.max_network_retries'))->toBe(2);
});

it('does not call Stripe again for a replayed event', function (): void {
    pendingPayment('cs_test_enrich_replay');
    $event = completedEvent('evt_enrich_replay', 'cs_test_enrich_replay', 'pi_enrich_replay');

    $calls = 0;
    $this->app->instance(StripeClient::class, fakeWebhookClient(
        $event,
        function (string $id) use (&$calls): CheckoutSession {
            $calls++;

            return expandedSession($id, 'pi_enrich_replay', 'ch_r', 'https://pay.stripe.com/receipts/r');
        },
    ));

    postStripeWebhook()->assertOk();
    postStripeWebhook()->assertOk();

    expect($calls)->toBe(1);
});

it('never overwrites stored charge data with nulls or new values during enrichment', function (): void {
    $payment = pendingPayment('cs_test_enrich_keep');
    $payment->forceFill([
        'stripe_charge_id' => 'ch_original',
        'receipt_url' => 'https://pay.stripe.com/receipts/original',
    ])->save();
    $event = completedEvent('evt_enrich_keep', 'cs_test_enrich_keep', 'pi_enrich_keep');

    $this->app->instance(StripeClient::class, fakeWebhookClient(
        $event,
        fn (string $id): CheckoutSession => CheckoutSession::constructFrom([
            'id' => $id,
            'payment_intent' => ['id' => 'pi_enrich_keep', 'latest_charge' => ['id' => 'ch_other', 'receipt_url' => null]],
        ]),
    ));

    postStripeWebhook()->assertOk();

    $payment->refresh();
    expect($payment->stripe_charge_id)->toBe('ch_original')
        ->and($payment->receipt_url)->toBe('https://pay.stripe.com/receipts/original');
});

it('fills only the missing column when the charge has no receipt url', function (): void {
    $payment = pendingPayment('cs_test_enrich_partial');
    $event = completedEvent('evt_enrich_partial', 'cs_test_enrich_partial', 'pi_enrich_partial');

    $this->app->instance(StripeClient::class, fakeWebhookClient(
        $event,
        fn (string $id): CheckoutSession => CheckoutSession::constructFrom([
            'id' => $id,
            'payment_intent' => ['id' => 'pi_enrich_partial', 'latest_charge' => ['id' => 'ch_partial']],
        ]),
    ));

    postStripeWebhook()->assertOk();

    $payment->refresh();
    expect($payment->stripe_charge_id)->toBe('ch_partial')
        ->and($payment->receipt_url)->toBeNull();
});

// ---------------------------------------------------------------------------
// Delayed payment methods (OXXO / SPEI)
// ---------------------------------------------------------------------------

function sessionEvent(string $eventId, string $type, string $sessionId, string $paymentStatus): Event
{
    return Event::constructFrom([
        'id' => $eventId,
        'type' => $type,
        'livemode' => false,
        'data' => ['object' => CheckoutSession::constructFrom([
            'id' => $sessionId,
            'customer' => 'cus_'.$sessionId,
            'payment_status' => $paymentStatus,
            'payment_intent' => 'pi_'.$sessionId,
        ])],
    ]);
}

it('leaves the payment pending when checkout completes with an unpaid delayed method', function (): void {
    $payment = pendingPayment('cs_delayed');
    $this->app->instance(StripeClient::class, fakeWebhookClient(
        sessionEvent('evt_delayed_done', 'checkout.session.completed', 'cs_delayed', 'unpaid')
    ));

    postStripeWebhook()->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending)
        ->and($payment->fresh()->membership_id)->toBeNull()
        ->and(Membership::count())->toBe(0);
});

it('activates a delayed-method payment once async_payment_succeeded arrives, idempotently', function (): void {
    $payment = pendingPayment('cs_delayed2');

    // The controller is cached on the route within a test: keep one fake, swap its event.
    $client = fakeWebhookClient(sessionEvent('evt_delayed2_done', 'checkout.session.completed', 'cs_delayed2', 'unpaid'));
    $this->app->instance(StripeClient::class, $client);
    postStripeWebhook()->assertOk();
    expect(Membership::count())->toBe(0);

    $client->event = sessionEvent('evt_delayed2_ok', 'checkout.session.async_payment_succeeded', 'cs_delayed2', 'paid');
    postStripeWebhook()->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Succeeded)
        ->and($payment->fresh()->stripe_payment_intent_id)->toBe('pi_cs_delayed2')
        ->and(Membership::count())->toBe(1);

    $client->event = sessionEvent('evt_delayed2_ok_again', 'checkout.session.async_payment_succeeded', 'cs_delayed2', 'paid');
    postStripeWebhook()->assertOk();

    expect(Membership::count())->toBe(1);
});

it('does not activate when the session payment status is not paid', function (string $status): void {
    $payment = pendingPayment('cs_np_'.$status);

    $returned = app(MembershipService::class)->activateFromCheckoutSession(CheckoutSession::constructFrom([
        'id' => 'cs_np_'.$status,
        'payment_status' => $status,
        'payment_intent' => 'pi_np',
    ]));

    expect($returned->status)->toBe(PaymentStatus::Pending)
        ->and(Membership::count())->toBe(0);
})->with(['unpaid', 'no_payment_required']);

// ---------------------------------------------------------------------------
// Unmatched checkout events: never a 3-day silent 500 loop
// ---------------------------------------------------------------------------

/** @param  array<string, string>  $metadata */
function unmatchedSessionEvent(string $eventId, string $type, array $metadata, ?int $created = null): Event
{
    return Event::constructFrom([
        'id' => $eventId,
        'type' => $type,
        'livemode' => false,
        'created' => $created ?? time(),
        'data' => ['object' => CheckoutSession::constructFrom([
            'id' => 'cs_'.$eventId,
            'customer' => 'cus_x',
            'payment_status' => 'paid',
            'payment_intent' => 'pi_x',
            'metadata' => $metadata,
        ])],
    ]);
}

it('acks a checkout event from another integration that matches no payment', function (string $type): void {
    Notification::fake();
    $this->app->instance(StripeClient::class, fakeWebhookClient(unmatchedSessionEvent('evt_foreign_'.$type, $type, [])));

    postStripeWebhook()->assertOk();

    expect(StripeWebhookEvent::where('event_id', 'evt_foreign_'.$type)->exists())->toBeTrue()
        ->and(Membership::count())->toBe(0);
    Notification::assertNothingSent();
})->with([
    'checkout.session.completed',
    'checkout.session.async_payment_succeeded',
    'checkout.session.async_payment_failed',
    'checkout.session.expired',
]);

it('keeps retrying our tagged checkout event that matches no payment while it is young', function (string $type): void {
    Notification::fake();
    config(['billing.reversal_retry_window_hours' => 24]);
    $this->app->instance(StripeClient::class, fakeWebhookClient(
        unmatchedSessionEvent('evt_ours_young_'.$type, $type, ['app' => 'humae'], time() - 3600)
    ));

    postStripeWebhook()->assertStatus(500);

    expect(StripeWebhookEvent::count())->toBe(0);
    Notification::assertNothingSent();
})->with(['checkout.session.completed', 'checkout.session.expired']);

it('alerts billing and acks our tagged checkout event once the retry window has passed', function (string $type): void {
    Notification::fake();
    config(['billing.reversal_retry_window_hours' => 24, 'billing.email' => 'billing@example.test']);
    $this->app->instance(StripeClient::class, fakeWebhookClient(
        unmatchedSessionEvent('evt_ours_old_'.$type, $type, ['app' => 'humae'], time() - 25 * 3600)
    ));

    postStripeWebhook()->assertOk();

    expect(StripeWebhookEvent::where('event_id', 'evt_ours_old_'.$type)->exists())->toBeTrue();
    Notification::assertSentOnDemandTimes(BillingAlertNotification::class, 1);
})->with(['checkout.session.completed', 'checkout.session.async_payment_failed']);

it('activates a legacy untagged session that matches a payment', function (): void {
    pendingPayment('cs_legacy');
    $this->app->instance(StripeClient::class, fakeWebhookClient(completedEvent('evt_legacy', 'cs_legacy', 'pi_legacy')));

    postStripeWebhook()->assertOk();

    expect(Membership::count())->toBe(1);
});

it('alerts billing and acks when the matched payment has no plan', function (): void {
    Notification::fake();
    config(['billing.email' => 'billing@example.test']);
    $payment = pendingPayment('cs_noplan');
    Payment::whereKey($payment->id)->update(['membership_plan_id' => null]);
    $this->app->instance(StripeClient::class, fakeWebhookClient(completedEvent('evt_noplan', 'cs_noplan', 'pi_noplan')));

    postStripeWebhook()->assertOk();

    expect(Membership::count())->toBe(0)
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Pending);
    Notification::assertSentOnDemandTimes(BillingAlertNotification::class, 1);
});
