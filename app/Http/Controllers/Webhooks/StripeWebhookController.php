<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Helpers\StripeClient;
use App\Http\Controllers\Controller;
use App\Services\MembershipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Checkout\Session as CheckoutSession;
use Stripe\Event;
use Symfony\Component\HttpFoundation\Response as HttpStatus;
use Throwable;
use UnexpectedValueException;

class StripeWebhookController extends Controller
{
    public function __construct(
        private readonly StripeClient $stripe,
        private readonly MembershipService $memberships,
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

        try {
            $this->dispatch($event);
        } catch (Throwable $e) {
            Log::error('Stripe webhook handler failed.', [
                'event' => $event->type,
                'event_id' => $event->id,
                'exception' => $e->getMessage(),
            ]);

            // Devuelve 500 para que Stripe reintente.
            return $this->error('Handler failed.', status: HttpStatus::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->success(message: 'Event processed.', data: ['type' => $event->type]);
    }

    private function dispatch(Event $event): void
    {
        switch ($event->type) {
            case 'checkout.session.completed':
                /** @var CheckoutSession $session */
                $session = $event->data->object;
                $this->memberships->activateFromCheckoutSession($session);
                break;

            default:
                Log::info('Stripe webhook event ignored.', ['type' => $event->type]);
                break;
        }
    }
}
