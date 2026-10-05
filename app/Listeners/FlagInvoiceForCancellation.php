<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\InvoiceRequestStatus;
use App\Events\PaymentReversed;
use App\Exceptions\InvalidInvoiceRequestTransitionException;
use App\Models\InvoiceRequest;
use App\Services\InvoiceRequestService;
use App\Services\PaymentReversalService;
use Illuminate\Support\Facades\Log;

/**
 * Keeps CFDI requests coherent with a reversed payment (full refund or lost dispute).
 *
 * Runs synchronously inside the reversal (webhook) transaction, so it only writes
 * to the database; the billing alerts it raises are queued after commit. It is
 * idempotent: the transition itself is the guard, and a request already in
 * `cancellation_pending` is left alone.
 *
 * - issued: `cancellation_pending`, billing must cancel the CFDI with the SAT.
 * - requested / in progress: `rejected` with an automatic reason; claims released.
 *
 * A transition that cannot be applied must never fail the webhook (Stripe would
 * retry a state we cannot change): it is logged and escalated to billing.
 */
final class FlagInvoiceForCancellation
{
    public function __construct(
        private readonly InvoiceRequestService $requests,
        private readonly PaymentReversalService $alerts,
    ) {}

    public function handle(PaymentReversed $event): void
    {
        $payment = $event->payment;

        $request = InvoiceRequest::query()
            ->whereHas('payments', fn ($q) => $q->where('claimed_payment_id', $payment->id))
            ->first();

        if ($request === null || $request->status === InvoiceRequestStatus::CancellationPending) {
            return;
        }

        $target = match ($request->status) {
            InvoiceRequestStatus::Requested, InvoiceRequestStatus::InProgress => InvoiceRequestStatus::Rejected,
            // Issued, or a terminal request that still holds a claim (inconsistent
            // data): the attempt either works or surfaces through the failure path.
            default => InvoiceRequestStatus::CancellationPending,
        };

        $reason = match ($event->reason) {
            'dispute_lost' => 'Disputa de pago perdida',
            default => 'Pago reembolsado',
        };

        try {
            $from = $this->requests->transition($request, $target, $target === InvoiceRequestStatus::Rejected ? $reason : null);
        } catch (InvalidInvoiceRequestTransitionException $e) {
            Log::error('Invoice request could not follow a reversed payment.', [
                'invoice_request_id' => $request->id,
                'payment_id' => $payment->id,
                'from' => $e->from->value,
                'to' => $e->to->value,
            ]);

            $this->alerts->alertBilling(
                $payment,
                'Invoice request needs manual review',
                "Payment {$payment->id} was reversed ({$event->reason}) but invoice request #{$request->id} is {$e->from->value} and cannot move to {$e->to->value}. Review it manually.",
            );

            return;
        }

        // The audit entry carries ids and statuses only: never the RFC or legal name.
        activity('invoice-requests')
            ->performedOn($request)
            ->withProperties([
                'invoice_request_id' => $request->id,
                'payment_id' => $payment->id,
                'from' => $from->value,
                'to' => $target->value,
                'reason' => $event->reason,
            ])
            ->log('El pago se revirtió: se actualizó la solicitud de factura.');

        if ($target === InvoiceRequestStatus::Rejected) {
            $this->alerts->alertBilling(
                $payment,
                'Invoice request rejected after a reversed payment',
                "Payment {$payment->id} was reversed ({$event->reason}); invoice request #{$request->id} was {$from->value} and has been rejected automatically. No CFDI had been issued.",
            );

            return;
        }

        $this->alerts->alertBilling(
            $payment,
            'CFDI must be cancelled with the SAT',
            "Payment {$payment->id} was reversed ({$event->reason}). Invoice request #{$request->id} (CFDI {$request->cfdi_uuid}) is now cancellation pending: cancel the CFDI with the SAT, then mark the request as cancelled.",
        );
    }
}
