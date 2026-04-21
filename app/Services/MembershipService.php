<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MembershipStatus;
use App\Enums\PaymentStatus;
use App\Helpers\StripeClient;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\MembershipActivatedNotification;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Stripe\Checkout\Session as CheckoutSession;

class MembershipService
{
    public function __construct(
        private readonly StripeClient $stripe,
    ) {}

    /**
     * Crea una Checkout Session de Stripe con `price_data` inline,
     * asocia un Payment en estado `pending` y devuelve la URL de checkout.
     *
     * @return array{url: string, session_id: string, payment_id: int}
     */
    public function createCheckoutSession(User $user, MembershipPlan $plan): array
    {
        $frontend = rtrim((string) config('app.frontend_url', 'http://localhost:3000'), '/');

        $successUrl = $frontend.'/membership/success?session_id={CHECKOUT_SESSION_ID}';
        $cancelUrl = $frontend.'/membership/cancel';

        $currency = strtolower(
            $plan->currency !== null
                ? $plan->currency->code
                : (string) config('services.stripe.currency', 'mxn')
        );

        // price_data inline — Stripe genera un product/price efímero por sesión
        $session = $this->stripe->createCheckoutSession([
            'mode' => 'payment',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'customer_email' => $user->email,
            'client_reference_id' => (string) $user->id,
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => $currency,
                    'unit_amount' => (int) round(((float) $plan->price) * 100),
                    'product_data' => [
                        'name' => $plan->name,
                        'description' => $plan->description ?? null,
                    ],
                ],
            ]],
            'metadata' => [
                'user_id' => (string) $user->id,
                'membership_plan_id' => (string) $plan->id,
                'plan_code' => (string) $plan->code,
            ],
        ]);

        $payment = Payment::create([
            'user_id' => $user->id,
            'membership_plan_id' => $plan->id,
            'status' => PaymentStatus::Pending->value,
            'salary_currency_id' => $plan->salary_currency_id,
            'amount' => $plan->price,
            'fee_amount' => 0,
            'net_amount' => $plan->price,
            'provider' => 'stripe',
            'stripe_session_id' => $session->id,
            'stripe_customer_id' => is_string($session->customer) ? $session->customer : null,
            'metadata' => [
                'plan_code' => $plan->code,
                'session_url' => $session->url,
            ],
        ]);

        return [
            'url' => (string) $session->url,
            'session_id' => (string) $session->id,
            'payment_id' => (int) $payment->id,
        ];
    }

    /**
     * Marca el pago como `succeeded` y crea la membresía asociada,
     * calculando `expires_at` con base en `duration_days` del plan.
     */
    public function activateFromCheckoutSession(CheckoutSession $session): Payment
    {
        /** @var Payment|null $payment */
        $payment = Payment::where('stripe_session_id', $session->id)->first();

        if ($payment === null) {
            throw new RuntimeException("Payment not found for Stripe session {$session->id}");
        }

        return DB::transaction(function () use ($payment, $session): Payment {
            if ($payment->status === PaymentStatus::Succeeded) {
                return $payment; // idempotente: webhook puede dispararse múltiples veces
            }

            $plan = $payment->plan;

            if ($plan === null) {
                throw new RuntimeException("MembershipPlan not found for payment {$payment->id}");
            }

            $now = now();
            $expiresAt = $now->copy()->addDays((int) $plan->duration_days);

            $membership = Membership::create([
                'user_id' => $payment->user_id,
                'membership_plan_id' => $plan->id,
                'status' => MembershipStatus::Active->value,
                'started_at' => $now,
                'expires_at' => $expiresAt,
                'auto_renew' => false,
            ]);

            $paymentIntentId = is_string($session->payment_intent)
                ? $session->payment_intent
                : (is_object($session->payment_intent) ? (string) $session->payment_intent->id : null);

            $customerId = is_string($session->customer)
                ? $session->customer
                : (is_object($session->customer) ? (string) $session->customer->id : null);

            $payment->update([
                'status' => PaymentStatus::Succeeded->value,
                'membership_id' => $membership->id,
                'stripe_payment_intent_id' => $paymentIntentId,
                'stripe_customer_id' => $customerId ?? $payment->stripe_customer_id,
                'paid_at' => $now,
            ]);

            $user = $payment->user;
            if ($user !== null) {
                $user->notify(new MembershipActivatedNotification($membership));
            }

            $refreshed = $payment->fresh(['membership', 'plan']);

            return $refreshed ?? $payment;
        });
    }

    /**
     * Marca como `expired` todas las membresías activas cuya fecha de expiración ya pasó.
     * Retorna la cantidad actualizada.
     */
    public function expireStale(): int
    {
        return Membership::query()
            ->where('status', MembershipStatus::Active->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update([
                'status' => MembershipStatus::Expired->value,
                'updated_at' => now(),
            ]);
    }

    public function cancel(Membership $membership, ?string $reason = null): Membership
    {
        $membership->forceFill([
            'status' => MembershipStatus::Cancelled->value,
            'cancelled_at' => now(),
            'cancel_reason' => $reason,
        ])->save();

        return $membership;
    }
}
