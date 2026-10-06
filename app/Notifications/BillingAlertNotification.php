<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Alert to the billing mailbox about a payment that needs human review.
 *
 * Queued and sent only after the surrounding transaction commits, so a rolled
 * back webhook never emits an alert and an SMTP failure never undoes a reversal.
 */
class BillingAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** A billing alert is the only signal for a refund/dispute: retry through SMTP hiccups. */
    public int $tries = 5;

    public function __construct(
        public readonly string $subject,
        public readonly string $body,
    ) {
        $this->afterCommit = true;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    /**
     * Final failure: the alert never reached billing. Subject and exception class
     * only; the body can name payments and invoice requests.
     */
    public function failed(Throwable $e): void
    {
        Log::critical('Billing alert could not be delivered after all retries.', [
            'subject' => $this->subject,
            'exception' => $e::class,
        ]);
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->subject)
            ->line($this->body);
    }
}
