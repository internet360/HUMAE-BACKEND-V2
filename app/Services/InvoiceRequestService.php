<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\InvoiceRequestStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\InvalidInvoiceRequestTransitionException;
use App\Exceptions\PaymentNotEligibleException;
use App\Models\InvoiceRequest;
use App\Models\InvoiceRequestPayment;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\InvoiceRequestRejectedNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;

class InvoiceRequestService
{
    /** Dispute outcomes that leave the charge intact. */
    private const SAFE_DISPUTE_STATUSES = ['won', 'warning_closed', 'charge_refunded'];

    public function __construct(private readonly CfdiDeadlinePolicy $deadline) {}

    /**
     * Succeeded, not refunded (a partial refund also excludes it: billing issues
     * those by hand at the net amount), no open/lost dispute, not claimed by an
     * active request, inside the deadline window.
     *
     * @return Builder<Payment>
     */
    public function eligibleQuery(User $user): Builder
    {
        return Payment::query()
            ->where('user_id', $user->id)
            ->where('status', PaymentStatus::Succeeded)
            ->whereNull('refunded_at')
            ->where(function (Builder $q): void {
                $q->whereNull('refund_amount')->orWhere('refund_amount', '<=', 0);
            })
            ->whereNull('metadata->refund_review')
            ->whereNotNull('paid_at')
            ->where('paid_at', '>=', $this->deadline->windowStart())
            ->where(function (Builder $q): void {
                $q->whereNull('metadata->dispute_status')
                    ->orWhereIn('metadata->dispute_status', self::SAFE_DISPUTE_STATUSES);
            })
            ->whereNotExists(function ($q): void {
                $q->selectRaw('1')
                    ->from('invoice_request_payments')
                    ->whereColumn('invoice_request_payments.claimed_payment_id', 'payments.id');
            });
    }

    /** @return Collection<int, Payment> */
    public function eligiblePayments(User $user): Collection
    {
        return $this->eligibleQuery($user)->orderBy('paid_at')->orderBy('id')->get();
    }

    /**
     * Persists the request and claims every payment, or none.
     *
     * @param  list<int>  $paymentIds
     * @param  array<string, string>  $fiscal  already validated fiscal fields
     *
     * @throws PaymentNotEligibleException
     */
    public function claim(User $user, array $paymentIds, array $fiscal): InvoiceRequest
    {
        $paymentIds = array_values(array_unique($paymentIds));

        try {
            return DB::transaction(function () use ($user, $paymentIds, $fiscal): InvoiceRequest {
                $eligible = $this->eligibleQuery($user)->whereIn('id', $paymentIds)->orderBy('id')->lockForUpdate()->get();

                if ($paymentIds === [] || $eligible->count() !== count($paymentIds)) {
                    throw new PaymentNotEligibleException($this->reasonFor($user, $paymentIds, $eligible));
                }

                $request = InvoiceRequest::create([
                    ...$fiscal,
                    'user_id' => $user->id,
                    'status' => InvoiceRequestStatus::Requested,
                ]);

                foreach ($eligible as $payment) {
                    $request->payments()->create([
                        'payment_id' => $payment->id,
                        'claimed_payment_id' => $payment->id,
                        'amount' => $payment->amount,
                        'paid_at' => $payment->paid_at,
                    ]);
                }

                return $request->load('payments');
            }, 3);
        } catch (UniqueConstraintViolationException) {
            // A concurrent request won the claim between our check and insert.
            throw new PaymentNotEligibleException('claimed');
        }
    }

    /**
     * Moves the request to a releasing status and frees its payments.
     *
     * The row is locked and the transition checked against the persisted
     * status, so an issued request can never free payments that already back
     * an invoice.
     *
     * @throws InvalidInvoiceRequestTransitionException
     */
    public function releaseClaims(InvoiceRequest $request, InvoiceRequestStatus $to): void
    {
        if (! $to->releasesClaim()) {
            throw new InvalidArgumentException("Status {$to->value} does not release claims.");
        }

        DB::transaction(function () use ($request, $to): void {
            $locked = InvoiceRequest::query()->lockForUpdate()->findOrFail($request->id);

            if (! $locked->status->canTransitionTo($to)) {
                throw new InvalidInvoiceRequestTransitionException($locked->status, $to);
            }

            $locked->update(['status' => $to]);
            InvoiceRequestPayment::where('invoice_request_id', $locked->id)->update(['claimed_payment_id' => null]);

            $request->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Admin status change. `issued` is deliberately unreachable here: the
     * invoice files upload is the only way to issue.
     *
     * Releasing statuses free the claims and a rejection stores its reason in
     * the same transaction. Reasons for other statuses are not persisted on the
     * row: the controller records them in the audit entry.
     *
     * @return InvoiceRequestStatus the status the request had before
     *
     * @throws InvalidInvoiceRequestTransitionException
     */
    public function transition(InvoiceRequest $request, InvoiceRequestStatus $to, ?string $reason = null): InvoiceRequestStatus
    {
        if ($to === InvoiceRequestStatus::Issued) {
            throw new InvalidArgumentException('Issued is only reachable by uploading the invoice files.');
        }

        return DB::transaction(function () use ($request, $to, $reason): InvoiceRequestStatus {
            $locked = InvoiceRequest::query()->lockForUpdate()->findOrFail($request->id);
            $from = $locked->status;

            if ($to->releasesClaim()) {
                $this->releaseClaims($locked, $to);
            } elseif ($from->canTransitionTo($to)) {
                $locked->update(['status' => $to]);
            } else {
                throw new InvalidInvoiceRequestTransitionException($from, $to);
            }

            if ($to === InvoiceRequestStatus::Rejected) {
                $locked->update(['rejection_reason' => $reason]);
            }

            $request->setRawAttributes($locked->getAttributes(), true);

            if ($to === InvoiceRequestStatus::Rejected) {
                $this->notifyRejection($locked);
            }

            return $from;
        });
    }

    /**
     * Queued after commit. Only reached by a real transition into rejected (a
     * replay throws before getting here), so it cannot be sent twice.
     */
    private function notifyRejection(InvoiceRequest $request): void
    {
        $paymentIds = InvoiceRequestPayment::query()->where('invoice_request_id', $request->id)->pluck('payment_id')->all();
        $user = $request->user;

        $requestable = $user === null ? 0 : $this->eligibleQuery($user)->whereIn('id', $paymentIds)->count();

        Notification::route('mail', $request->email)
            ->notify(new InvoiceRequestRejectedNotification($request, $requestable, count($paymentIds)));
    }

    /**
     * @param  list<int>  $ids
     * @param  Collection<int, Payment>  $eligible
     */
    private function reasonFor(User $user, array $ids, Collection $eligible): string
    {
        $missing = Payment::query()->where('user_id', $user->id)->whereIn('id', array_diff($ids, $eligible->modelKeys()))->get();

        if ($missing->count() !== count($ids) - $eligible->count()) {
            return 'not_found';
        }

        $claimed = InvoiceRequestPayment::whereIn('claimed_payment_id', $missing->modelKeys())->exists();
        if ($claimed) {
            return 'claimed';
        }

        return $missing->contains(fn (Payment $p) => $p->status === PaymentStatus::Succeeded
            && $p->refunded_at === null
            && $p->paid_at !== null
            && ! $this->deadline->allows($p->paid_at)) ? 'deadline' : 'not_eligible';
    }
}
