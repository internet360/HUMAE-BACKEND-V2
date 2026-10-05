<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Admin;

use App\Models\InvoiceRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The list shows who asked and whether billing was told; the fiscal data
 * (RFC, legal name) only travels in the detail view.
 *
 * @mixin InvoiceRequest
 */
class AdminInvoiceRequestResource extends JsonResource
{
    public function __construct(mixed $resource, private readonly bool $detailed = false)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $base = [
            'id' => $this->id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'user' => $this->whenLoaded('user', fn (): array => [
                'id' => $this->user?->id,
                'name' => $this->user?->name,
                'email' => $this->user?->email,
            ]),
            'billing_notified_at' => $this->billing_notified_at?->toIso8601String(),
            'billing_notification' => $this->billing_notified_at === null ? 'pending' : 'sent',
            'payments_count' => $this->whenCounted(
                'payments',
                fn ($count): int => (int) $count,
                fn () => $this->whenLoaded('payments', fn (): int => $this->payments->count()),
            ),
            'created_at' => $this->created_at?->toIso8601String(),
        ];

        if (! $this->detailed) {
            return $base;
        }

        return [
            ...$base,
            'rfc' => $this->rfc,
            'legal_name' => $this->legal_name,
            'tax_regime' => $this->tax_regime,
            'postal_code' => $this->postal_code,
            'cfdi_use' => $this->cfdi_use,
            'email' => $this->email,
            'rejection_reason' => $this->rejection_reason,
            'admin_notes' => $this->admin_notes,
            'payments' => $this->payments->map(fn ($p): array => [
                'payment_id' => $p->payment_id,
                'amount' => (float) $p->amount,
                'paid_at' => $p->paid_at->toIso8601String(),
            ])->values()->all(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
