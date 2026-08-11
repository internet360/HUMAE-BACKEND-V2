<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CandidateState;
use App\Enums\MembershipStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Helpers\StripeClient;
use App\Models\CandidateProfile;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\MembershipActivatedNotification;
use App\Notifications\MembershipExpiredNotification;
use App\Notifications\MembershipExpiringNotification;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Stripe\Checkout\Session as CheckoutSession;

class MembershipService
{
    /**
     * Días de anticipación con los que se avisa que la membresía va a vencer.
     *
     * La ventana es `(now, now + N días]`: arranca N días antes y llega hasta
     * el mismo día del vencimiento. El aviso sale una única vez dentro de esa
     * ventana, no una vez por día.
     */
    public const EXPIRY_WARNING_DAYS = 3;

    public function __construct(
        private readonly StripeClient $stripe,
        private readonly ProfileService $profiles,
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

        $successUrl = $frontend.'/membership/success?cs={CHECKOUT_SESSION_ID}';
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
                $this->promoteCandidateToActive($user);
                $user->notify(new MembershipActivatedNotification($membership));
            }

            $refreshed = $payment->fresh(['membership', 'plan']);

            return $refreshed ?? $payment;
        });
    }

    /**
     * Cuando un candidato paga su membresía, su CandidateProfile.state pasa
     * de `registro_incompleto` / `pendiente_pago` / `membresia_vencida` → `activo`.
     * Si el candidato todavía no tiene un perfil (ej. pagó antes de abrir /me/profile),
     * lo creamos aquí mismo para que aparezca en el directorio. No sobreescribe
     * estados avanzados del pipeline (en_proceso, entrevistado, etc.).
     */
    private function promoteCandidateToActive(User $user): void
    {
        // Reached from the Stripe webhook, so it runs unauthenticated. Only a
        // candidate has a place in the directory; promoting anybody else would
        // be the F-09 side effect arriving by another door.
        if (! $user->hasRole(UserRole::Candidate->value)) {
            return;
        }

        $profile = $this->profiles->findOrCreate($user);

        $promotable = [
            CandidateState::RegistroIncompleto->value,
            CandidateState::PendientePago->value,
            CandidateState::MembresiaVencida->value,
            null,
        ];

        $current = $profile->state instanceof CandidateState
            ? $profile->state->value
            : $profile->state;

        if (in_array($current, $promotable, true)) {
            $profile->forceFill(['state' => CandidateState::Activo->value])->save();
        }
    }

    /**
     * Marca como `expired` todas las membresías activas cuya fecha de expiración ya pasó.
     * Retorna la cantidad actualizada.
     */
    public function expireStale(): int
    {
        $affectedUserIds = Membership::query()
            ->where('status', MembershipStatus::Active->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->pluck('user_id')
            ->unique()
            ->all();

        $count = Membership::query()
            ->where('status', MembershipStatus::Active->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update([
                'status' => MembershipStatus::Expired->value,
                'updated_at' => now(),
            ]);

        if ($count > 0 && $affectedUserIds !== []) {
            // Solo demotamos candidatos que estaban en estado `activo`; los que
            // ya estaban en pipeline avanzado (en_proceso, entrevistado...) se
            // respetan para que la expiración no descarte trabajo en curso.
            CandidateProfile::query()
                ->whereIn('user_id', $affectedUserIds)
                ->where('state', CandidateState::Activo->value)
                ->update([
                    'state' => CandidateState::MembresiaVencida->value,
                    'updated_at' => now(),
                ]);
        }

        return $count;
    }

    /**
     * Avisa que la membresía está por vencer, una sola vez por membresía.
     *
     * Ventana en días de CALENDARIO: vence hoy o dentro de los próximos N
     * días. El límite inferior `expires_at > now` deja fuera a las que ya
     * vencieron — de esas se ocupa `notifyExpired()`, con la otra plantilla.
     *
     * El corte superior va por fecha (`whereDate`) y no por instante, porque el
     * job corre a una hora fija. Con `expires_at <= now()->addDays(3)`, una
     * membresía que vence el día E a las 09:00 seguía fuera de la ventana en la
     * corrida de E-3 a la 01:00 y sólo entraba en E-2: el aviso salía dos días
     * antes en lugar de tres, incumpliendo el requerimiento. Comparar fechas lo
     * ancla al día calendario, igual que la copia del correo.
     *
     * Cuesta el índice de `expires_at` (la función sobre la columna lo
     * inutiliza). Es un job diario sobre una tabla chica: se prefiere que la
     * regla de negocio sea correcta.
     *
     * El candado es `expiry_warning_sent_at`, no un cálculo de fechas: el job
     * corre a diario y la ventana dura varios días, así que sin marca
     * persistida el mismo candidato recibiría el aviso cada mañana.
     *
     * El sello se escribe DESPUÉS de notificar a propósito. Si el envío falla,
     * la marca queda nula y la corrida de mañana lo reintenta; al revés
     * perderíamos el aviso en silencio.
     *
     * @return int cuántos avisos se enviaron
     */
    public function notifyExpiring(int $daysBefore = self::EXPIRY_WARNING_DAYS): int
    {
        $memberships = Membership::query()
            ->where('status', MembershipStatus::Active->value)
            ->whereNull('expiry_warning_sent_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', now())
            ->whereDate('expires_at', '<=', now()->addDays($daysBefore)->toDateString())
            ->with('user')
            ->get();

        return $this->dispatchNotices(
            $memberships,
            fn (Membership $m) => new MembershipExpiringNotification($m),
            'expiry_warning_sent_at',
        );
    }

    /**
     * Avisa que la membresía ya expiró, una sola vez por membresía.
     *
     * Se apoya en el status y no en la fecha: `expireStale()` es quien decide
     * que una membresía está vencida, y el middleware `EnsureActiveMembership`
     * corta el acceso por el mismo status. Mandar "ya expiró" mientras el
     * candidato todavía entra a la plataforma sería contradecirnos.
     *
     * Por eso el job corre después de `ExpireMembershipsJob` (ver
     * `bootstrap/app.php`). Si algún día se invirtiera el orden, el sello hace
     * que el aviso simplemente salga en la corrida siguiente, no que se pierda.
     *
     * @return int cuántos avisos se enviaron
     */
    public function notifyExpired(): int
    {
        $memberships = Membership::query()
            ->where('status', MembershipStatus::Expired->value)
            ->whereNull('expired_notice_sent_at')
            ->with('user')
            ->get();

        return $this->dispatchNotices(
            $memberships,
            fn (Membership $m) => new MembershipExpiredNotification($m),
            'expired_notice_sent_at',
        );
    }

    /**
     * Notifica y sella. Compartido por los dos avisos porque la mecánica
     * —notificar, marcar, contar, saltar las huérfanas— es idéntica y sólo
     * cambian la plantilla y la columna del candado.
     *
     * @param  EloquentCollection<int, Membership>  $memberships
     * @param  callable(Membership): Notification  $notification
     */
    private function dispatchNotices(
        EloquentCollection $memberships,
        callable $notification,
        string $sentAtColumn,
    ): int {
        $sent = 0;

        foreach ($memberships as $membership) {
            $user = $membership->user;

            // Sin usuario no hay a quién escribirle. Se deja el sello nulo por
            // si el dato se repara más adelante.
            if ($user === null) {
                continue;
            }

            $user->notify($notification($membership));

            $membership->forceFill([$sentAtColumn => now()])->save();
            $sent++;
        }

        return $sent;
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
