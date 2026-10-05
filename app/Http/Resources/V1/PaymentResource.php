<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Payment
 */
class PaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status?->value,
            'amount' => (float) $this->amount,
            'currency' => $this->currency?->code,
            'provider' => $this->provider,
            'receipt_url' => $this->receipt_url,
            'refund_amount' => $this->refund_amount !== null ? (float) $this->refund_amount : null,
            'refund_review' => (bool) ($this->metadata['refund_review'] ?? false),
            'dispute_status' => $this->metadata['dispute_status'] ?? null,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'refunded_at' => $this->refunded_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
