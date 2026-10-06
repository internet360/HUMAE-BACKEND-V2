<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CandidateState;
use App\Enums\InvoiceRequestStatus;
use App\Enums\MembershipStatus;
use App\Enums\PaymentStatus;
use App\Events\PaymentReversed;
use App\Models\CandidateProfile;
use App\Models\InvoiceRequest;
use App\Models\Membership;
use App\Models\Payment;
use App\Notifications\BillingAlertNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Stripe\Charge;
use Stripe\Checkout\Session as CheckoutSession;
use Stripe\Dispute;
use Stripe\StripeObject;

/**
 * Refunds, disputes and failed checkouts.
 *
 * Business rules (proposal assumptions, kept in one place on purpose):
 * partial refund = flag billing only; full refund = revoke; dispute opened =
 * flag only; dispute lost = revoke; dispute won = clear the flag.
 */
class PaymentReversalService
{
    public function handleRefund(Charge $charge, ?int $eventCreated = null, ?string $eventId = null): void
    {
        DB::transaction(function () use ($charge, $eventCreated, $eventId): void {
            $payment = $this->lockPayment($this->idOf($charge->payment_intent ?? null), $this->idOf($charge->id ?? null));

            if ($payment === null) {
                $this->handleUnmatched($charge, 'charge.refunded', $eventCreated, $eventId);

                return;
            }

            if ($payment->status === PaymentStatus::Refunded) {
                return;
            }

            $refunded = ((int) ($charge->amount_refunded ?? 0)) / 100;

            if ($refunded >= ((int) ($charge->amount ?? 0)) / 100 && $refunded > 0) {
                $this->revokeAccess($payment, 'refunded', number_format($refunded, 2, '.', ''), $eventId);

                return;
            }

            // Partial: remember the amount and ask billing to review; no revoke.
            // A replay or stale event (amount not above the stored one) changes nothing and stays silent.
            // A stale (out-of-order) event carries a lower or equal total: ignore it.
            if ($refunded <= (float) $payment->refund_amount) {
                return;
            }

            $payment->forceFill([
                'refund_amount' => number_format($refunded, 2, '.', ''),
                'metadata' => [...($payment->metadata ?? []), 'refund_review' => true],
            ])->save();

            $this->alertBilling($payment, 'Partial refund needs review', "Payment {$payment->id} was partially refunded ({$refunded}).".$this->issuedInvoiceNote($payment));
        });
    }

    public function handleDisputeCreated(Dispute $dispute, ?int $eventCreated = null, ?string $eventId = null): void
    {
        DB::transaction(function () use ($dispute, $eventCreated, $eventId): void {
            $payment = $this->lockPayment($this->idOf($dispute->payment_intent ?? null), $this->idOf($dispute->charge ?? null));

            if ($payment === null) {
                $this->handleUnmatched($dispute, 'charge.dispute.created', $eventCreated, $eventId);

                return;
            }

            // Only a payment with no dispute state moves to open. Events can arrive
            // out of order: a `created` that shows up after the dispute closed
            // (won, lost, warning_closed, charge_refunded) must not reopen it.
            if (($payment->metadata['dispute_status'] ?? null) !== null) {
                return;
            }

            $payment->forceFill([
                'metadata' => [...($payment->metadata ?? []), 'dispute_status' => 'open'],
            ])->save();

            $this->alertBilling($payment, 'Payment dispute opened', "Payment {$payment->id} has an open dispute.");
        });
    }

    public function handleDisputeClosed(Dispute $dispute, ?int $eventCreated = null, ?string $eventId = null): void
    {
        DB::transaction(function () use ($dispute, $eventCreated, $eventId): void {
            $payment = $this->lockPayment($this->idOf($dispute->payment_intent ?? null), $this->idOf($dispute->charge ?? null));

            if ($payment === null) {
                $this->handleUnmatched($dispute, 'charge.dispute.closed', $eventCreated, $eventId);

                return;
            }

            // Every closed outcome (won, lost, warning_closed, charge_refunded) is
            // terminal: the flag moves off `open` so billing never sees a stale one.
            $metadata = [
                ...($payment->metadata ?? []),
                'dispute_status' => (string) $dispute->status,
                'dispute_amount' => number_format(((int) ($dispute->amount ?? 0)) / 100, 2, '.', ''),
            ];
            $payment->forceFill(['metadata' => $metadata])->save();

            if ($dispute->status !== 'lost') {
                return;
            }

            if ($payment->status === PaymentStatus::Refunded) {
                // Already refunded and now the dispute is lost too: the money may
                // have left twice. A human must check; nothing else to revoke.
                $this->alertBilling($payment, 'Dispute lost on a refunded payment', "Payment {$payment->id} was already refunded and its dispute was lost: possible double loss.");

                return;
            }

            $this->revokeAccess($payment, 'dispute_lost', null, $eventId);
        });
    }

    /**
     * A checkout that failed or expired never granted access; only a pending
     * payment may fail. Returns false when no payment has that session at all.
     */
    public function failPendingCheckout(CheckoutSession $session): bool
    {
        if (! Payment::where('stripe_session_id', $session->id)->exists()) {
            return false;
        }

        Payment::where('stripe_session_id', $session->id)
            ->where('status', PaymentStatus::Pending->value)
            ->update(['status' => PaymentStatus::Failed->value, 'updated_at' => now()]);

        return true;
    }

    /**
     * Idempotent: an already refunded payment is a no-op. Runs in one transaction
     * that locks only the payment row; the membership goes Refunded and the
     * candidate profile only leaves `activo` (advanced pipeline states are never
     * demoted). The audit amount never goes down: with a figure it is the max of
     * the stored and the new one; without one (dispute lost) a stored partial
     * refund is kept, otherwise the full price is recorded.
     */
    public function revokeAccess(Payment $payment, string $reason, ?string $refundAmount = null, ?string $eventId = null): void
    {
        DB::transaction(function () use ($payment, $reason, $refundAmount, $eventId): void {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($payment->status === PaymentStatus::Refunded) {
                return;
            }

            $payment->forceFill([
                'status' => PaymentStatus::Refunded->value,
                'refunded_at' => now(),
                'refund_amount' => $this->auditRefundAmount($payment, $refundAmount),
                'refund_reason' => $reason,
            ])->save();

            $membership = $payment->membership;

            if ($membership !== null && in_array($membership->status, [MembershipStatus::Active, MembershipStatus::Expired], true)) {
                $membership->forceFill([
                    'status' => MembershipStatus::Refunded->value,
                    'cancelled_at' => now(),
                    'cancel_reason' => $reason,
                ])->save();

                $this->demoteProfile($payment->user_id, $membership->id);
            }

            $this->alertBilling($payment, 'Payment reversed', "Payment {$payment->id} was reversed ({$reason}); access revoked.");

            event(new PaymentReversed($payment, $reason, $eventId));
        });
    }

    /** Points billing at the issued CFDI a partial refund may require a credit note for. No RFC. */
    private function issuedInvoiceNote(Payment $payment): string
    {
        $request = InvoiceRequest::query()
            ->whereIn('status', [InvoiceRequestStatus::Issued->value, InvoiceRequestStatus::CancellationPending->value])
            ->whereHas('payments', fn ($q) => $q->where('claimed_payment_id', $payment->id))
            ->first();

        if ($request === null) {
            return '';
        }

        return " Invoice request #{$request->id} (CFDI {$request->cfdi_uuid}) is already issued and stays unchanged: review whether a credit note is needed.";
    }

    private function auditRefundAmount(Payment $payment, ?string $refundAmount): string
    {
        $stored = (float) $payment->refund_amount;

        if ($refundAmount === null) {
            return number_format($stored > 0 ? $stored : (float) $payment->amount, 2, '.', '');
        }

        return number_format(max($stored, (float) $refundAmount), 2, '.', '');
    }

    private function demoteProfile(int $userId, int $revokedMembershipId): void
    {
        $holdsAnother = Membership::query()
            ->where('user_id', $userId)
            ->whereKeyNot($revokedMembershipId)
            ->where('status', MembershipStatus::Active->value)
            ->where('expires_at', '>', now())
            ->exists();

        if ($holdsAnother) {
            return;
        }

        CandidateProfile::query()
            ->where('user_id', $userId)
            ->where('state', CandidateState::Activo->value)
            ->update(['state' => CandidateState::MembresiaVencida->value, 'updated_at' => now()]);
    }

    /**
     * Matches by payment intent first, then charge. Backfills the charge id and,
     * when the payment has none yet, the intent id (unless another payment already
     * owns that intent: the column is unique, so we only log). Returns null when
     * nothing matches; the caller decides between retrying and acking.
     */
    private function lockPayment(?string $paymentIntentId, ?string $chargeId): ?Payment
    {
        $payment = null;

        if ($paymentIntentId !== null) {
            $payment = Payment::where('stripe_payment_intent_id', $paymentIntentId)->lockForUpdate()->first();
        }

        if ($payment === null && $chargeId !== null) {
            $payment = Payment::where('stripe_charge_id', $chargeId)->lockForUpdate()->first();
        }

        if ($payment === null) {
            return null;
        }

        $backfill = [];

        if ($payment->stripe_charge_id === null && $chargeId !== null) {
            $backfill['stripe_charge_id'] = $chargeId;
        }

        if ($payment->stripe_payment_intent_id === null && $paymentIntentId !== null) {
            if (Payment::where('stripe_payment_intent_id', $paymentIntentId)->whereKeyNot($payment->id)->exists()) {
                Log::warning('Payment intent already linked to another payment; skipping backfill.', [
                    'payment_id' => $payment->id,
                    'payment_intent' => $paymentIntentId,
                ]);
            } else {
                $backfill['stripe_payment_intent_id'] = $paymentIntentId;
            }
        }

        if ($backfill !== []) {
            $payment->forceFill($backfill)->save();
        }

        return $payment;
    }

    /**
     * No payment matched. Objects not tagged `app=humae` belong to someone else
     * (another integration on the same Stripe account), or predate the tagging:
     * ack, never retry. Ours may simply have arrived before the payment row, so
     * keep failing (Stripe retries) only while the event is younger than the
     * configured window; past it, escalate to billing and ack so the endpoint is
     * not disabled by an endless 500 loop.
     *
     * `$untaggedLevel` is `warning` for reversals (an untagged charge could be a
     * legacy one of ours, so a human should be able to find it by event id) and
     * `info` for checkout sessions (other integrations create them constantly).
     */
    public function handleUnmatched(object $object, string $eventType, ?int $eventCreated, ?string $eventId = null, string $untaggedLevel = 'warning'): void
    {
        $context = ['event_type' => $eventType, 'object_id' => $this->idOf($object->id ?? null), 'stripe_event_id' => $eventId];

        if ($this->metadataApp($object) !== 'humae') {
            Log::log($untaggedLevel, 'Stripe event ignored: its object is not tagged as created by this app.', $context);

            return;
        }

        $windowHours = (int) config('billing.reversal_retry_window_hours', 24);
        $ageSeconds = $eventCreated === null ? 0 : max(0, now()->getTimestamp() - $eventCreated);

        if ($ageSeconds <= $windowHours * 3600) {
            throw new RuntimeException("No payment found for {$eventType} object {$context['object_id']}");
        }

        Log::error('Stripe event for our object never matched a payment; giving up.', $context + ['age_hours' => intdiv($ageSeconds, 3600)]);

        $this->alertBilling(null, 'Unmatched Stripe event', "Stripe {$eventType} for {$context['object_id']} (tagged as ours) matched no payment after {$windowHours}h of retries. Review it manually.");
    }

    private function metadataApp(object $object): ?string
    {
        $metadata = $object->metadata ?? null;

        if (! $metadata instanceof StripeObject) {
            return null;
        }

        $app = $metadata['app'] ?? null;

        return is_string($app) ? $app : null;
    }

    private function idOf(mixed $value): ?string
    {
        return is_object($value) ? (string) ($value->id ?? '') : (is_string($value) ? $value : null);
    }

    public function alertBilling(?Payment $payment, string $subject, string $body): void
    {
        $address = config('billing.email');

        if (! is_string($address) || $address === '') {
            Log::warning('Billing alert skipped: BILLING_EMAIL is not configured.', [
                'payment_id' => $payment?->id,
                'subject' => $subject,
            ]);

            return;
        }

        Notification::route('mail', $address)->notify(new BillingAlertNotification($subject, $body));
    }
}
