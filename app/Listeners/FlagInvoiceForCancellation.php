<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\InvoiceRequestStatus;
use App\Events\PaymentReversed;
use App\Exceptions\InvalidInvoiceRequestTransitionException;
use App\Models\InvoiceRequest;
use App\Models\InvoiceRequestPayment;
use App\Models\Payment;
use App\Services\CfdiDeadlinePolicy;
use App\Services\InvoiceRequestService;
use App\Services\PaymentReversalService;
use Illuminate\Support\Facades\DB;
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
 * The request row is locked before the target is chosen (lock order: payment,
 * held by the caller, then request, as in `InvoiceRequestService::claim`), so a
 * concurrent admin upload (requested to issued) cannot make us target `rejected`
 * from a stale status.
 *
 * A transition that cannot be applied must never fail the webhook (Stripe would
 * retry a state we cannot change): it is logged and escalated to billing.
 */
final class FlagInvoiceForCancellation
{
    public function __construct(
        private readonly InvoiceRequestService $requests,
        private readonly PaymentReversalService $alerts,
        private readonly CfdiDeadlinePolicy $deadline,
    ) {}

    public function handle(PaymentReversed $event): void
    {
        $payment = $event->payment;

        $requestId = InvoiceRequest::query()
            ->whereHas('payments', fn ($q) => $q->where('claimed_payment_id', $payment->id))
            ->value('id');

        if ($requestId === null) {
            return;
        }

        DB::transaction(function () use ($event, $payment, $requestId): void {
            $request = InvoiceRequest::query()->whereKey($requestId)->lockForUpdate()->first();

            if ($request === null || $request->status === InvoiceRequestStatus::CancellationPending) {
                return;
            }

            $reason = match ($event->reason) {
                'dispute_lost' => 'Disputa de pago perdida',
                default => 'Pago reembolsado',
            };

            // The row is locked, so its status cannot change under us; the single
            // retry covers a status the first target was not valid for.
            $tried = null;

            while (true) {
                $target = $this->targetFor($request->status);

                try {
                    $from = $this->requests->transition($request, $target, $target === InvoiceRequestStatus::Rejected ? $reason : null);
                    break;
                } catch (InvalidInvoiceRequestTransitionException $e) {
                    $request->refresh();

                    if ($tried === null && $this->targetFor($request->status) !== $target) {
                        $tried = $target;

                        continue;
                    }

                    Log::error('Invoice request could not follow a reversed payment.', [
                        'invoice_request_id' => $request->id,
                        'payment_id' => $payment->id,
                        'from' => $e->from->value,
                        'to' => $e->to->value,
                    ]);

                    $this->alerts->alertBilling(
                        $payment,
                        'Invoice request needs manual review',
                        "Payment {$payment->id} was reversed ({$event->reason}) but invoice request #{$request->id} is {$e->from->value}: the automatic move to {$e->to->value} is not allowed. Review it manually.".$this->otherPaymentsNote($request, $payment, issued: null),
                    );

                    return;
                }
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
                    "Payment {$payment->id} was reversed ({$event->reason}); invoice request #{$request->id} was {$from->value} and has been rejected automatically. No CFDI had been issued.".$this->otherPaymentsNote($request, $payment, issued: false),
                );

                return;
            }

            $this->alerts->alertBilling(
                $payment,
                'CFDI must be cancelled with the SAT',
                "Payment {$payment->id} was reversed ({$event->reason}). Invoice request #{$request->id} (CFDI {$request->cfdi_uuid}) is now cancellation pending: cancel the CFDI with the SAT, then mark the request as cancelled.".$this->otherPaymentsNote($request, $payment, issued: true),
            );
        });
    }

    private function targetFor(InvoiceRequestStatus $status): InvoiceRequestStatus
    {
        return match ($status) {
            InvoiceRequestStatus::Requested, InvoiceRequestStatus::InProgress => InvoiceRequestStatus::Rejected,
            // Issued, or a terminal request that still holds a claim (inconsistent
            // data): the attempt either works or surfaces through the failure path.
            default => InvoiceRequestStatus::CancellationPending,
        };
    }

    /**
     * The request may cover several payments. Billing needs to see the others:
     * on an issued CFDI they lose their invoice once it is cancelled; on a
     * rejection their claims were released. Ids, amounts and dates only: no RFC.
     */
    private function otherPaymentsNote(InvoiceRequest $request, Payment $reversed, ?bool $issued): string
    {
        $others = InvoiceRequestPayment::query()
            ->where('invoice_request_id', $request->id)
            ->where('payment_id', '!=', $reversed->id)
            ->orderBy('payment_id')
            ->get();

        if ($others->isEmpty()) {
            return '';
        }

        $timezone = (string) config('billing.timezone');
        $lines = $others->map(fn (InvoiceRequestPayment $p): string => sprintf(
            'Payment %d: %s, paid %s',
            $p->payment_id,
            $p->amount,
            $p->paid_at->copy()->setTimezone($timezone)->format('Y-m-d H:i'),
        ))->implode('; ');

        $windowStart = $this->deadline->windowStart()->setTimezone($timezone)->format('Y-m-d H:i');

        if ($issued === null) {
            return " Other payments on this request ({$timezone}): {$lines}.";
        }

        $consequence = $issued
            ? 'These payments are covered by the CFDI being cancelled and need a new CFDI once the cancellation completes'
            : 'These payments were released and can be requested again by the candidate';

        return " Other payments on this request ({$timezone}): {$lines}. {$consequence}; the deadline policy applies (payments before {$windowStart} are outside the current window and must be invoiced manually).";
    }
}
