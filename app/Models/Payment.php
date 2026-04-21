<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentStatus;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $membership_id
 * @property int|null $membership_plan_id
 * @property PaymentStatus|null $status
 * @property int|null $salary_currency_id
 * @property string $amount
 * @property string $fee_amount
 * @property string $net_amount
 * @property string $provider
 * @property string|null $stripe_session_id
 * @property string|null $stripe_payment_intent_id
 * @property string|null $stripe_charge_id
 * @property string|null $stripe_customer_id
 * @property string|null $receipt_url
 * @property Carbon|null $paid_at
 * @property Carbon|null $refunded_at
 * @property string|null $refund_amount
 * @property string|null $refund_reason
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'membership_id',
        'membership_plan_id',
        'status',
        'salary_currency_id',
        'amount',
        'fee_amount',
        'net_amount',
        'provider',
        'stripe_session_id',
        'stripe_payment_intent_id',
        'stripe_charge_id',
        'stripe_customer_id',
        'receipt_url',
        'paid_at',
        'refunded_at',
        'refund_amount',
        'refund_reason',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'amount' => 'decimal:2',
            'fee_amount' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'refund_amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'refunded_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Membership, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    /** @return BelongsTo<MembershipPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class, 'membership_plan_id');
    }

    /** @return BelongsTo<SalaryCurrency, $this> */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(SalaryCurrency::class, 'salary_currency_id');
    }
}
