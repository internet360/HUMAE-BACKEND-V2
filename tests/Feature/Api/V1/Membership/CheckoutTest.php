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
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;
use Stripe\Checkout\Session as CheckoutSession;
use Stripe\Customer;

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

        /** @param  array<string, mixed>  $params */
        public function createCustomer(array $params, string $idempotencyKey): Customer
        {
            $this->customerCalls[] = ['params' => $params, 'key' => $idempotencyKey];

            return Customer::constructFrom(['id' => 'cus_new_'.count($this->customerCalls)]);
        }

        /** @param  array<string, mixed>  $params */
        public function createCheckoutSession(array $params): CheckoutSession
        {
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

it('creates one Stripe customer per user and attaches it to the session', function (): void {
    $user = User::factory()->create();
    $user->assignRole(UserRole::Candidate->value);
    Sanctum::actingAs($user);

    $stripe = fakeStripeClient();
    $this->app->instance(StripeClient::class, $stripe);

    $this->postJson('/api/v1/me/membership/checkout')->assertCreated();

    expect($stripe->customerCalls)->toHaveCount(1)
        ->and($stripe->customerCalls[0]['key'])->toBe('customer-user-'.$user->id)
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
