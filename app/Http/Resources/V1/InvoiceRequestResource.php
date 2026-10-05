<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\InvoiceRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shown to the owner (and admins) only, so the RFC is included here although
 * the model hides it by default.
 *
 * @mixin InvoiceRequest
 */
class InvoiceRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'rfc' => $this->rfc,
            'legal_name' => $this->legal_name,
            'tax_regime' => $this->tax_regime,
            'postal_code' => $this->postal_code,
            'cfdi_use' => $this->cfdi_use,
            'email' => $this->email,
            'rejection_reason' => $this->rejection_reason,
            'has_pdf' => $this->pdf_path !== null,
            'has_xml' => $this->xml_path !== null,
            'cfdi_uuid' => $this->cfdi_uuid,
            'issued_at' => $this->issued_at?->toIso8601String(),
            'payments' => $this->payments->map(fn ($p): array => [
                'payment_id' => $p->payment_id,
                'amount' => (float) $p->amount,
                'paid_at' => $p->paid_at->toIso8601String(),
            ])->values()->all(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
