<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\InvoiceRequest;
use App\Notifications\InvoiceRequestedNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Emails the billing mailbox about a new invoice request and stamps
 * `billing_notified_at` on success, so the admin can see which requests the
 * billing team was never told about. The request is persisted before this job
 * is dispatched: a mail failure never loses it.
 *
 * Holds only the id (never the fiscal data) and never logs the RFC or legal
 * name; exception messages are scrubbed before logging.
 */
class NotifyBillingOfInvoiceRequestJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $invoiceRequestId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(): void
    {
        $request = InvoiceRequest::find($this->invoiceRequestId);

        // Gone or already notified (a retry after a stamp): nothing to send.
        if ($request === null || $request->billing_notified_at !== null) {
            return;
        }

        $address = (string) config('billing.email', '');

        if ($address === '') {
            Log::error('Billing mailbox not configured; invoice request not notified.', [
                'invoice_request_id' => $request->id,
            ]);

            return;
        }

        Notification::route('mail', $address)->notifyNow(new InvoiceRequestedNotification($request));

        $request->forceFill(['billing_notified_at' => now()])->save();
    }

    public function failed(Throwable $e): void
    {
        $request = InvoiceRequest::find($this->invoiceRequestId);

        Log::error('Billing notification for an invoice request failed.', [
            'invoice_request_id' => $this->invoiceRequestId,
            'exception' => $e::class,
            'reason' => $request === null ? $e->getMessage() : $this->scrub($e->getMessage(), $request),
        ]);
    }

    private function scrub(string $message, InvoiceRequest $request): string
    {
        return str_ireplace([$request->rfc, $request->legal_name, $request->email], '[redacted]', $message);
    }
}
