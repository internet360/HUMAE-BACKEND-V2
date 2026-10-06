<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\StripeClient;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Keeps exactly one Stripe Customer per user.
 *
 * Must not be called inside an outer transaction: the Stripe HTTP call is made
 * with no database lock held, and only the final write takes a short row lock.
 */
class StripeCustomerService
{
    public function __construct(private readonly StripeClient $stripe) {}

    /**
     * Returns the user's Stripe customer id, creating the customer on first use.
     */
    public function ensureFor(User $user): string
    {
        $stored = $this->storedId($user);

        if ($stored !== null) {
            $user->setAttribute('stripe_customer_id', $stored);

            return $stored;
        }

        return $this->createAndStore($user, 'initial');
    }

    /**
     * Drops a customer id Stripe rejected (deleted, wrong mode, rotated
     * account) and creates a fresh one. The stored id is only cleared when it
     * still equals `$staleId`, so a concurrent request that already replaced it
     * is respected.
     */
    public function replaceStale(User $user, string $staleId): string
    {
        User::whereKey($user->id)
            ->where('stripe_customer_id', $staleId)
            ->update(['stripe_customer_id' => null]);

        $stored = $this->storedId($user);

        if ($stored !== null) {
            $user->setAttribute('stripe_customer_id', $stored);

            return $stored;
        }

        return $this->createAndStore($user, $staleId);
    }

    private function storedId(User $user): ?string
    {
        $id = User::whereKey($user->id)->value('stripe_customer_id');

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Idempotency key = user + generation + hash of the params sent.
     *
     * - A lost response or a concurrent double click sends identical params for
     *   the same generation, so Stripe returns the same customer.
     * - After a stale id is replaced the generation (the stale id) changes, and
     *   a profile edit changes the hash; either way the key is new, avoiding
     *   `idempotency_error` for the 24h Stripe keeps keys.
     */
    private function createAndStore(User $user, string $generation): string
    {
        $params = [
            'email' => $user->email,
            'name' => $user->name,
            'metadata' => ['user_id' => (string) $user->id],
        ];

        $key = sprintf(
            'customer-user-%d-%s-%s',
            $user->id,
            $generation,
            substr(hash('sha256', (string) json_encode($params)), 0, 16),
        );

        $customer = $this->stripe->createCustomer($params, $key);

        // Short transaction: lock, re-check, write only if still empty.
        return DB::transaction(function () use ($user, $customer): string {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            $existing = $locked->stripe_customer_id;
            if (is_string($existing) && $existing !== '') {
                $user->setAttribute('stripe_customer_id', $existing);

                return $existing;
            }

            $locked->forceFill(['stripe_customer_id' => $customer->id])->save();
            $user->setAttribute('stripe_customer_id', $customer->id);

            return (string) $customer->id;
        });
    }
}
