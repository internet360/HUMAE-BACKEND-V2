<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CandidateState;
use App\Enums\MembershipStatus;
use App\Enums\PaymentStatus;
use App\Events\PaymentReversed;
use App\Models\CandidateProfile;
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

/**
 * Refunds, disputes and failed checkouts.
 *
 * Business rules (proposal assumptions, kept in one place on purpose):
 * partial refund = flag billing only; full refund = revoke; dispute opened =
 * flag only; dispute lost = revoke; dispute won = clear the flag.
 */
class PaymentReversalService
{
    public function handleRefund(Charge $charge): void
    {
        DB::transaction(function () use ($charge): void {
            $payment = $this->lockPayment($this->idOf($charge->payment_intent ?? null), $this->idOf($charge->id ?? null));

            if ($payment->status === PaymentStatus::Refunded) {
                return;
            }

            $refunded = ((int) ($charge->amount_refunded ?? 0)) / 100;

            if ($refunded >= ((int) ($charge->amount ?? 0)) / 100 && $refunded > 0) {
                $this->revokeAccess($payment, 'refunded', number_format($refunded, 2, '.', ''));

                return;
            }

            // Partial: remember the amount and ask billing to review; no revoke.
            // A replay with the same amount changes nothing and stays silent.
            if ((float) $payment->refund_amount === $refunded) {
                return;
            }

            $payment->forceFill([
                'refund_amount' => number_format($refunded, 2, '.', ''),
                'metadata' => [...($payment->metadata ?? []), 'refund_review' => true],
            ])->save();

            $this->alertBilling($payment, 'Partial refund needs review', "Payment {$payment->id} was partially refunded ({$refunded}).");
        });
    }

    public function handleDisputeCreated(Dispute $dispute): void
    {
        DB::transaction(function () use ($dispute): void {
            $payment = $this->lockPayment($this->idOf($dispute->payment_intent ?? null), $this->idOf($dispute->charge ?? null));

            if (($payment->metadata['dispute_status'] ?? null) === 'open') {
                return;
            }

            $payment->forceFill([
                'metadata' => [...($payment->metadata ?? []), 'dispute_status' => 'open'],
            ])->save();

            $this->alertBilling($payment, 'Payment dispute opened', "Payment {$payment->id} has an open dispute.");
        });
    }

    public function handleDisputeClosed(Dispute $dispute): void
    {
        DB::transaction(function () use ($dispute): void {
            $payment = $this->lockPayment($this->idOf($dispute->payment_intent ?? null), $this->idOf($dispute->charge ?? null));

            if ($dispute->status === 'lost') {
                $this->revokeAccess($payment, 'dispute_lost');

                return;
            }

            if ($dispute->status === 'won') {
                $metadata = $payment->metadata ?? [];
                unset($metadata['dispute_status']);
                $payment->forceFill(['metadata' => $metadata])->save();
            }
        });
    }

    /** A checkout that failed or expired never granted access; only a pending payment may fail. */
    public function failPendingCheckout(CheckoutSession $session): void
    {
        Payment::where('stripe_session_id', $session->id)
            ->where('status', PaymentStatus::Pending->value)
            ->update(['status' => PaymentStatus::Failed->value, 'updated_at' => now()]);
    }

    /**
     * Idempotent: an already refunded payment is a no-op. Runs in one locked
     * transaction; the membership goes Refunded and the candidate profile only
     * leaves `activo` (advanced pipeline states are never demoted).
     */
    public function revokeAccess(Payment $payment, string $reason, ?string $refundAmount = null): void
    {
        DB::transaction(function () use ($payment, $reason, $refundAmount): void {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($payment->status === PaymentStatus::Refunded) {
                return;
            }

            $payment->forceFill([
                'status' => PaymentStatus::Refunded->value,
                'refunded_at' => now(),
                'refund_amount' => $refundAmount ?? $payment->amount,
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

            event(new PaymentReversed($payment, $reason));
        });
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
     * Matches by payment intent first, then charge, and backfills the charge id.
     * Not found (e.g. refund delivered before the completion) throws so the webhook
     * answers 500, drops the dedup row, and Stripe redelivers after completion.
     */
    private function lockPayment(?string $paymentIntentId, ?string $chargeId): Payment
    {
        $payment = null;

        if ($paymentIntentId !== null) {
            $payment = Payment::where('stripe_payment_intent_id', $paymentIntentId)->lockForUpdate()->first();
        }

        if ($payment === null && $chargeId !== null) {
            $payment = Payment::where('stripe_charge_id', $chargeId)->lockForUpdate()->first();
        }

        if ($payment === null) {
            throw new RuntimeException("No payment found for payment intent {$paymentIntentId} / charge {$chargeId}");
        }

        if ($payment->stripe_charge_id === null && $chargeId !== null) {
            $payment->forceFill(['stripe_charge_id' => $chargeId])->save();
        }

        return $payment;
    }

    private function idOf(mixed $value): ?string
    {
        return is_object($value) ? (string) ($value->id ?? '') : (is_string($value) ? $value : null);
    }

    private function alertBilling(Payment $payment, string $subject, string $body): void
    {
        $address = config('billing.email');

        if (! is_string($address) || $address === '') {
            Log::warning('Billing alert skipped: BILLING_EMAIL is not configured.', [
                'payment_id' => $payment->id,
                'subject' => $subject,
            ]);

            return;
        }

        Notification::route('mail', $address)->notify(new BillingAlertNotification($subject, $body));
    }
}
