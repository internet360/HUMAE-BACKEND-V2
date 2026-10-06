<?php

declare(strict_types=1);

namespace App\Helpers;

use App\Exceptions\StripeWebhookNotConfiguredException;
use RuntimeException;
use Stripe\Checkout\Session as CheckoutSession;
use Stripe\Customer;
use Stripe\Event;
use Stripe\HttpClient\CurlClient;
use Stripe\StripeClient as StripeSdkClient;
use Stripe\Webhook;

/**
 * Thin wrapper over the Stripe SDK to keep controllers/services testable.
 *
 * Bound as singleton in AppServiceProvider. Tests replace it via
 * `$this->app->instance(StripeClient::class, $fakeClient)`.
 */
class StripeClient
{
    private ?StripeSdkClient $client = null;

    public function __construct(
        private readonly ?string $secretKey,
        private readonly ?string $webhookSecret,
        private readonly int $connectTimeout = 5,
        private readonly int $timeout = 15,
        private readonly int $maxNetworkRetries = 2,
    ) {}

    /**
     * @param  array<string, mixed>  $params
     */
    public function createCheckoutSession(array $params): CheckoutSession
    {
        return $this->sdk()->checkout->sessions->create($params);
    }

    /**
     * The idempotency key makes a retried call return the original customer.
     *
     * @param  array<string, mixed>  $params
     */
    public function createCustomer(array $params, string $idempotencyKey): Customer
    {
        return $this->sdk()->customers->create($params, ['idempotency_key' => $idempotencyKey]);
    }

    /**
     * @param  array<string, mixed>  $params  e.g. `['expand' => ['payment_intent.latest_charge']]`
     */
    public function retrieveCheckoutSession(string $sessionId, array $params = []): CheckoutSession
    {
        return $this->sdk()->checkout->sessions->retrieve($sessionId, $params);
    }

    public function constructWebhookEvent(string $payload, string $signature): Event
    {
        if ($this->webhookSecret === null || $this->webhookSecret === '') {
            throw new StripeWebhookNotConfiguredException;
        }

        return Webhook::constructEvent($payload, $signature, $this->webhookSecret);
    }

    private function sdk(): StripeSdkClient
    {
        if ($this->secretKey === null || $this->secretKey === '') {
            throw new RuntimeException('Stripe secret key is not configured.');
        }

        if ($this->client === null) {
            // The SDK defaults (30s connect, 80s total, no retries) can hold a
            // PHP worker for minutes on a Stripe brownout. Timeouts live on the
            // shared curl client; retries are per client. Retried POSTs are safe
            // because the SDK reuses one idempotency key across attempts.
            CurlClient::instance()
                ->setConnectTimeout($this->connectTimeout)
                ->setTimeout($this->timeout);

            $this->client = new StripeSdkClient([
                'api_key' => $this->secretKey,
                'max_network_retries' => $this->maxNetworkRetries,
            ]);
        }

        return $this->client;
    }
}
