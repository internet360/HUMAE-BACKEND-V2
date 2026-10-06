<?php

declare(strict_types=1);

use App\Enums\MembershipStatus;
use App\Enums\UserRole;
use App\Helpers\StripeClient;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Payment;
use App\Models\SalaryCurrency;
use App\Models\User;
use App\Services\StripeCustomerService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;
use Stripe\Checkout\Session as CheckoutSession;
use Stripe\Customer;
use Stripe\Exception\InvalidRequestException;

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

function fakeStripeClient(): StripeClient
{
    return new class('sk_test_dummy', 'whsec_dummy') extends StripeClient
    {
        /** @var list<array{params: array<string, mixed>, key: string}> */
        public array $customerCalls = [];

        /** @var array<string, mixed> */
        public array $sessionParams = [];

        /** @var list<string> Customer ids Stripe no longer knows about. */
        public array $missingCustomers = [];

        public int $sessionAttempts = 0;

        /** @param  array<string, mixed>  $params */
        public function createCustomer(array $params, string $idempotencyKey): Customer
        {
            $this->customerCalls[] = ['params' => $params, 'key' => $idempotencyKey];

            return Customer::constructFrom(['id' => 'cus_new_'.count($this->customerCalls)]);
        }

        /** @param  array<string, mixed>  $params */
        public function createCheckoutSession(array $params): CheckoutSession
        {
            $this->sessionAttempts++;

            if (in_array($params['customer'] ?? null, $this->missingCustomers, true)) {
                $error = InvalidRequestException::factory('No such customer: '.$params['customer'], 400, null, null, null, 'resource_missing');
                $error->setStripeParam('customer');

                throw $error;
            }

            $this->sessionParams = $params;

            return CheckoutSession::constructFrom([
                'id' => 'cs_test_abc123',
                'url' => 'https://checkout.stripe.com/c/pay/cs_test_abc123',
                'customer' => $params['customer'] ?? null,
                'payment_intent' => 'pi_test_123',
            ]);
        }
    };
}

it('creates a Stripe Checkout Session and a pending Payment', function (): void {
    $user = User::factory()->create();
    $user->assignRole(UserRole::Candidate->value);
    Sanctum::actingAs($user);

    $this->app->instance(StripeClient::class, fakeStripeClient());

    $response = $this->postJson('/api/v1/me/membership/checkout');

    $response
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.session_id', 'cs_test_abc123')
        ->assertJsonPath('data.url', 'https://checkout.stripe.com/c/pay/cs_test_abc123');

    expect(Payment::where('user_id', $user->id)->count())->toBe(1);
    $payment = Payment::where('user_id', $user->id)->first();
    expect($payment->status->value)->toBe('pending');
    expect($payment->stripe_session_id)->toBe('cs_test_abc123');
});

it('tags the payment intent so reversal webhooks can tell our charges apart', function (): void {
    $user = User::factory()->create();
    $user->assignRole(UserRole::Candidate->value);
    Sanctum::actingAs($user);

    $stripe = fakeStripeClient();
    $this->app->instance(StripeClient::class, $stripe);

    $this->postJson('/api/v1/me/membership/checkout')->assertCreated();

    expect($stripe->sessionParams['payment_intent_data']['metadata']['app'])->toBe('humae')
        ->and($stripe->sessionParams['metadata']['app'])->toBe('humae')
        ->and($stripe->sessionParams['metadata']['user_id'])->toBe((string) $user->id);
});

it('creates one Stripe customer per user and attaches it to the session', function (): void {
    $user = User::factory()->create();
    $user->assignRole(UserRole::Candidate->value);
    Sanctum::actingAs($user);

    $stripe = fakeStripeClient();
    $this->app->instance(StripeClient::class, $stripe);

    $this->postJson('/api/v1/me/membership/checkout')->assertCreated();

    expect($stripe->customerCalls)->toHaveCount(1)
        ->and($stripe->customerCalls[0]['key'])->toStartWith('customer-user-'.$user->id.'-initial-')
        ->and($stripe->customerCalls[0]['params']['email'])->toBe($user->email)
        ->and($stripe->customerCalls[0]['params']['metadata']['user_id'])->toBe((string) $user->id)
        ->and($user->fresh()->stripe_customer_id)->toBe('cus_new_1')
        ->and($stripe->sessionParams['customer'])->toBe('cus_new_1')
        ->and($stripe->sessionParams)->not->toHaveKey('customer_email')
        ->and(Payment::where('user_id', $user->id)->first()->stripe_customer_id)->toBe('cus_new_1');
});

it('reuses the stored Stripe customer on a later checkout', function (): void {
    $user = User::factory()->create();
    $user->assignRole(UserRole::Candidate->value);
    $user->forceFill(['stripe_customer_id' => 'cus_existing'])->save();
    Sanctum::actingAs($user);

    $stripe = fakeStripeClient();
    $this->app->instance(StripeClient::class, $stripe);

    $this->postJson('/api/v1/me/membership/checkout')->assertCreated();

    expect($stripe->customerCalls)->toBeEmpty()
        ->and($stripe->sessionParams['customer'])->toBe('cus_existing')
        ->and($user->fresh()->stripe_customer_id)->toBe('cus_existing');
});

it('recovers when the stored Stripe customer no longer exists', function (): void {
    $user = User::factory()->create();
    $user->assignRole(UserRole::Candidate->value);
    $user->forceFill(['stripe_customer_id' => 'cus_gone'])->save();
    Sanctum::actingAs($user);

    $stripe = fakeStripeClient();
    $stripe->missingCustomers = ['cus_gone'];
    $this->app->instance(StripeClient::class, $stripe);

    $this->postJson('/api/v1/me/membership/checkout')->assertCreated();

    expect($stripe->sessionAttempts)->toBe(2)
        ->and($stripe->customerCalls)->toHaveCount(1)
        ->and($stripe->customerCalls[0]['key'])->toContain('cus_gone')
        ->and($stripe->sessionParams['customer'])->toBe('cus_new_1')
        ->and($user->fresh()->stripe_customer_id)->toBe('cus_new_1')
        ->and(Payment::where('user_id', $user->id)->first()->stripe_customer_id)->toBe('cus_new_1');
});

it('does not retry more than once when the fresh customer is also rejected', function (): void {
    $user = User::factory()->create();
    $user->assignRole(UserRole::Candidate->value);
    $user->forceFill(['stripe_customer_id' => 'cus_gone'])->save();
    Sanctum::actingAs($user);

    $stripe = fakeStripeClient();
    $stripe->missingCustomers = ['cus_gone', 'cus_new_1'];
    $this->app->instance(StripeClient::class, $stripe);

    $this->postJson('/api/v1/me/membership/checkout')->assertStatus(502);

    expect($stripe->sessionAttempts)->toBe(2)
        ->and($stripe->customerCalls)->toHaveCount(1)
        ->and(Payment::where('user_id', $user->id)->count())->toBe(0);
});

it('uses a different idempotency key for a recreated customer than for the first one', function (): void {
    $user = User::factory()->create();
    $user->assignRole(UserRole::Candidate->value);
    Sanctum::actingAs($user);

    $stripe = fakeStripeClient();
    $this->app->instance(StripeClient::class, $stripe);
    $this->postJson('/api/v1/me/membership/checkout')->assertCreated();

    $stripe->missingCustomers = ['cus_new_1'];
    $this->postJson('/api/v1/me/membership/checkout')->assertCreated();

    expect($stripe->customerCalls)->toHaveCount(2)
        ->and($stripe->customerCalls[1]['key'])->not->toBe($stripe->customerCalls[0]['key'])
        ->and($user->fresh()->stripe_customer_id)->toBe('cus_new_2');
});

it('keeps the idempotency key stable for identical params so a double click maps to one customer', function (): void {
    $user = User::factory()->create();
    $user->assignRole(UserRole::Candidate->value);
    Sanctum::actingAs($user);

    $stripe = fakeStripeClient();
    $this->app->instance(StripeClient::class, $stripe);

    $service = app(StripeCustomerService::class);
    $service->ensureFor($user);
    User::whereKey($user->id)->update(['stripe_customer_id' => null]);
    $service->ensureFor($user->fresh());

    expect($stripe->customerCalls[1]['key'])->toBe($stripe->customerCalls[0]['key']);
});

it('blocks checkout when user already has an active membership', function (): void {
    $user = User::factory()->create();
    $user->assignRole(UserRole::Candidate->value);

    $plan = MembershipPlan::where('code', 'candidate_6m')->first();

    Membership::factory()->create([
        'user_id' => $user->id,
        'membership_plan_id' => $plan->id,
        'status' => MembershipStatus::Active,
        'started_at' => now(),
        'expires_at' => now()->addDays(100),
    ]);

    Sanctum::actingAs($user);

    $response = $this->postJson('/api/v1/me/membership/checkout');

    $response->assertStatus(409);
});

it('requires authentication', function (): void {
    $this->postJson('/api/v1/me/membership/checkout')->assertStatus(401);
});
