<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\StripeClient;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Keeps exactly one Stripe Customer per user.
 */
class StripeCustomerService
{
    public function __construct(private readonly StripeClient $stripe) {}

    /**
     * Returns the user's Stripe customer id, creating the customer on first use.
     *
     * The user row is locked so two concurrent checkouts serialise, and the
     * idempotency key makes a retry after a lost response return the same
     * customer instead of creating a duplicate.
     */
    public function ensureFor(User $user): string
    {
        return DB::transaction(function () use ($user): string {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            if (is_string($locked->stripe_customer_id) && $locked->stripe_customer_id !== '') {
                $user->setAttribute('stripe_customer_id', $locked->stripe_customer_id);

                return $locked->stripe_customer_id;
            }

            $customer = $this->stripe->createCustomer([
                'email' => $locked->email,
                'name' => $locked->name,
                'metadata' => ['user_id' => (string) $locked->id],
            ], 'customer-user-'.$locked->id);

            $locked->forceFill(['stripe_customer_id' => $customer->id])->save();
            $user->setAttribute('stripe_customer_id', $customer->id);

            return (string) $customer->id;
        });
    }
}
