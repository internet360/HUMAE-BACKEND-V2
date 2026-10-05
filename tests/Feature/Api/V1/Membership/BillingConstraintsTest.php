<?php

declare(strict_types=1);

use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

it('rejects a second payment for the same payment intent', function (): void {
    Payment::factory()->create(['stripe_payment_intent_id' => 'pi_unique']);

    expect(fn () => Payment::factory()->create(['stripe_payment_intent_id' => 'pi_unique']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('allows many payments without a payment intent yet', function (): void {
    Payment::factory()->count(2)->create(['stripe_payment_intent_id' => null]);

    expect(Payment::whereNull('stripe_payment_intent_id')->count())->toBe(2);
});

it('rejects two users sharing a Stripe customer', function (): void {
    User::factory()->create()->forceFill(['stripe_customer_id' => 'cus_shared'])->save();

    $other = User::factory()->create();

    expect(fn () => $other->forceFill(['stripe_customer_id' => 'cus_shared'])->save())
        ->toThrow(UniqueConstraintViolationException::class);
});

it('allows many users without a Stripe customer', function (): void {
    User::factory()->count(2)->create();

    expect(User::whereNull('stripe_customer_id')->count())->toBe(2);
});

it('aborts the unique payment intent migration when duplicates already exist', function (): void {
    $migration = require database_path('migrations/2026_10_05_120200_add_unique_payment_intent_to_payments_table.php');

    $migration->down();
    Payment::factory()->count(2)->create(['stripe_payment_intent_id' => 'pi_dup']);

    expect(fn () => $migration->up())
        ->toThrow(RuntimeException::class, 'pi_dup');

    // Reconciled by hand: the migration now goes through.
    Payment::where('stripe_payment_intent_id', 'pi_dup')->orderByDesc('id')->first()?->delete();
    $migration->up();

    expect(fn () => Payment::factory()->create(['stripe_payment_intent_id' => 'pi_dup']))
        ->toThrow(UniqueConstraintViolationException::class);
});
