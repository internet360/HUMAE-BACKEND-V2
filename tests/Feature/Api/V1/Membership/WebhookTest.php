<?php

declare(strict_types=1);

use App\Enums\PaymentStatus;
use App\Helpers\StripeClient;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Payment;
use App\Models\SalaryCurrency;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Stripe\Checkout\Session as CheckoutSession;
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
});

function fakeWebhookClient(Event $event): StripeClient
{
    return new class('sk_test_dummy', 'whsec_dummy', $event) extends StripeClient
    {
        public function __construct(
            ?string $secretKey,
            ?string $webhookSecret,
            private readonly Event $event,
        ) {
            parent::__construct($secretKey, $webhookSecret);
        }

        public function constructWebhookEvent(string $payload, string $signature): Event
        {
            return $this->event;
        }
    };
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
