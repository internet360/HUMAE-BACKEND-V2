<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Helpers\StripeClient;
use App\Http\Controllers\Controller;
use App\Models\StripeWebhookEvent;
use App\Services\MembershipService;
use App\Services\PaymentReversalService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Charge;
use Stripe\Checkout\Session as CheckoutSession;
use Stripe\Dispute;
use Stripe\Event;
use Symfony\Component\HttpFoundation\Response as HttpStatus;
use Throwable;
use UnexpectedValueException;

class StripeWebhookController extends Controller
{
    public function __construct(
        private readonly StripeClient $stripe,
        private readonly MembershipService $memberships,
        private readonly PaymentReversalService $reversals,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $signature = (string) $request->header('Stripe-Signature', '');

        try {
            $event = $this->stripe->constructWebhookEvent($payload, $signature);
        } catch (UnexpectedValueException $e) {
            return $this->error('Invalid payload.', status: HttpStatus::HTTP_BAD_REQUEST);
        } catch (Throwable $e) {
            return $this->error('Invalid signature.', status: HttpStatus::HTTP_BAD_REQUEST);
        }

        if (StripeWebhookEvent::where('event_id', $event->id)->exists()) {
            return $this->alreadyProcessed();
        }

        try {
            $duplicate = DB::transaction(function () use ($event): bool {
                // The dedup row goes in FIRST: the unique index serialises
                // concurrent deliveries, and rolling the transaction back on any
                // handler failure means "row exists" always means "processed".
                try {
                    StripeWebhookEvent::create([
                        'event_id' => $event->id,
                        'type' => $event->type,
                        'livemode' => (bool) ($event->livemode ?? false),
                        'processed_at' => now(),
                    ]);
                } catch (UniqueConstraintViolationException) {
                    return true;
                }

                $this->dispatch($event);

                return false;
            });
        } catch (Throwable $e) {
            Log::error('Stripe webhook handler failed.', [
                'event' => $event->type,
                'event_id' => $event->id,
                'exception' => $e->getMessage(),
            ]);

            // Devuelve 500 para que Stripe reintente.
            return $this->error('Handler failed.', status: HttpStatus::HTTP_INTERNAL_SERVER_ERROR);
        }

        if ($duplicate) {
            return $this->alreadyProcessed();
        }

        if ($event->type === 'checkout.session.completed') {
            /** @var CheckoutSession $session */
            $session = $event->data->object;
            $this->memberships->enrichFromStripe((string) $session->id);
        }

        return $this->success(message: 'Event processed.', data: ['type' => $event->type]);
    }

    private function createdOf(Event $event): ?int
    {
        return isset($event->created) ? (int) $event->created : null;
    }

    private function alreadyProcessed(): JsonResponse
    {
        return $this->success(message: 'Event already processed.');
    }

    private function dispatch(Event $event): void
    {
        switch ($event->type) {
            case 'checkout.session.completed':
                /** @var CheckoutSession $session */
                $session = $event->data->object;
                $this->memberships->activateFromCheckoutSession($session);
                break;

            case 'checkout.session.async_payment_failed':
            case 'checkout.session.expired':
                /** @var CheckoutSession $session */
                $session = $event->data->object;
                $this->reversals->failPendingCheckout($session);
                break;

            case 'charge.refunded':
                /** @var Charge $charge */
                $charge = $event->data->object;
                $this->reversals->handleRefund($charge, $this->createdOf($event));
                break;

            case 'charge.dispute.created':
                /** @var Dispute $dispute */
                $dispute = $event->data->object;
                $this->reversals->handleDisputeCreated($dispute, $this->createdOf($event));
                break;

            case 'charge.dispute.closed':
                /** @var Dispute $dispute */
                $dispute = $event->data->object;
                $this->reversals->handleDisputeClosed($dispute, $this->createdOf($event));
                break;

            default:
                Log::info('Stripe webhook event ignored.', ['type' => $event->type]);
                break;
        }
    }
}
