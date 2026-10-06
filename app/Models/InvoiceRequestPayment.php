<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $invoice_request_id
 * @property int $payment_id
 * @property string $amount
 * @property Carbon $paid_at
 * @property int|null $claimed_payment_id
 */
class InvoiceRequestPayment extends Model
{
    protected $fillable = [
        'invoice_request_id',
        'payment_id',
        'amount',
        'paid_at',
        'claimed_payment_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<InvoiceRequest, $this> */
    public function invoiceRequest(): BelongsTo
    {
        return $this->belongsTo(InvoiceRequest::class);
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
