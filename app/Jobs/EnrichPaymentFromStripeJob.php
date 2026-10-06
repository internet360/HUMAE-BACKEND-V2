<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Helpers\StripeClient;
use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Copies the charge id and receipt url from Stripe onto a payment.
 *
 * Queued after the webhook commits so a slow or failing Stripe call can neither
 * roll back (or hold locks on) the activation nor slow the webhook response, and
 * a failure is retried with backoff instead of being lost. Idempotent and
 * fill-only: a column is written only when Stripe sent a value AND it is still
 * NULL, so a replay can never erase or rewrite data. Reversals match by payment
 * intent, so the charge id is not critical once retries are exhausted.
 */
class EnrichPaymentFromStripeJob implements ShouldQueueAfterCommit
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $sessionId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(StripeClient $stripe): void
    {
        $missing = Payment::where('stripe_session_id', $this->sessionId)
            ->where(fn ($q) => $q->whereNull('stripe_charge_id')->orWhereNull('receipt_url'))
            ->exists();

        // Gone, or already enriched (a replay): no reason to call Stripe.
        if (! $missing) {
            return;
        }

        $session = $stripe->retrieveCheckoutSession($this->sessionId, [
            'expand' => ['payment_intent.latest_charge'],
        ]);

        $intent = $session->payment_intent;
        $charge = is_object($intent) ? ($intent->latest_charge ?? null) : null;

        if (! is_object($charge)) {
            return;
        }

        $values = [
            'stripe_charge_id' => is_string($charge->id ?? null) && $charge->id !== '' ? $charge->id : null,
            'receipt_url' => is_string($charge->receipt_url ?? null) && $charge->receipt_url !== '' ? $charge->receipt_url : null,
        ];

        foreach ($values as $column => $value) {
            if ($value === null) {
                continue;
            }

            Payment::where('stripe_session_id', $this->sessionId)
                ->whereNull($column)
                ->update([$column => $value]);
        }
    }

    public function failed(Throwable $e): void
    {
        Log::warning('Stripe charge enrichment failed after all retries.', [
            'session_id' => $this->sessionId,
            'exception' => $e::class,
        ]);
    }
}
