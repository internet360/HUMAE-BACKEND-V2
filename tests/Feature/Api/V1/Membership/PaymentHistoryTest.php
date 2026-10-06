<?php

declare(strict_types=1);

use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Payment;
use App\Models\SalaryCurrency;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->mxn = SalaryCurrency::factory()->create(['code' => 'MXN']);
    $this->user = User::factory()->create();
    $this->user->assignRole(UserRole::Candidate->value);
    Sanctum::actingAs($this->user);
});

function historyPayment(User $user, SalaryCurrency $currency, array $attributes = []): Payment
{
    return Payment::factory()->create([
        'user_id' => $user->id,
        'salary_currency_id' => $currency->id,
        ...$attributes,
    ]);
}

it('exposes refund amount, review flag and dispute status for the history', function (): void {
    historyPayment($this->user, $this->mxn, [
        'status' => PaymentStatus::Succeeded,
        'refund_amount' => '100.00',
        'metadata' => ['refund_review' => true, 'dispute_status' => 'open'],
    ]);

    $this->getJson('/api/v1/me/payments')
        ->assertOk()
        ->assertJsonPath('data.0.refund_amount', 100)
        ->assertJsonPath('data.0.refund_review', true)
        ->assertJsonPath('data.0.dispute_status', 'open');
});

it('returns neutral values for a clean payment', function (): void {
    historyPayment($this->user, $this->mxn);

    $this->getJson('/api/v1/me/payments')
        ->assertOk()
        ->assertJsonPath('data.0.refund_amount', null)
        ->assertJsonPath('data.0.refund_review', false)
        ->assertJsonPath('data.0.dispute_status', null);
});

it('never leaks stripe ids or raw metadata', function (): void {
    historyPayment($this->user, $this->mxn, [
        'stripe_payment_intent_id' => 'pi_secret',
        'stripe_charge_id' => 'ch_secret',
        'metadata' => ['app' => 'humae', 'dispute_amount' => '499.00', 'dispute_status' => 'lost'],
    ]);

    $response = $this->getJson('/api/v1/me/payments')->assertOk();

    $item = $response->json('data.0');
    expect(array_keys($item))->not->toContain('metadata', 'stripe_payment_intent_id', 'stripe_charge_id', 'user_id', 'fee_amount', 'net_amount')
        ->and($response->getContent())->not->toContain('pi_secret', 'ch_secret', 'dispute_amount');
});
