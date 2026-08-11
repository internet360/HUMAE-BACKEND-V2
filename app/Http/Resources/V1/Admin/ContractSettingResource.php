<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Admin;

use App\Models\ContractSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ContractSetting
 */
class ContractSettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'provider_name' => $this->provider_name,
            'signatory_name' => $this->signatory_name,
            'signatory_title' => $this->signatory_title,

            // La ruta no viaja: es del disco privado. El panel usa el endpoint de
            // vista previa para mostrarla.
            'has_signature' => $this->signature_path !== null,

            'fee_kind' => $this->fee_kind,
            'fee_value' => $this->fee_value,
            'fee_amount_words' => $this->fee_amount_words,
            'payment_days' => $this->payment_days,
            'payment_day_kind' => $this->payment_day_kind,
            'warranty_days' => $this->warranty_days,
            'city' => $this->city,
            'jurisdiction' => $this->jurisdiction,

            'version' => $this->version,
            'updated_at' => $this->updated_at?->toIso8601String(),
            'updated_by' => $this->whenLoaded('updatedBy', fn () => $this->updatedBy?->name),

            /*
             * Qué falta para poder emitir un contrato. Se expone para que el admin
             * lo vea en el panel en vez de que una empresa se tope con el error
             * al intentar firmar.
             */
            'is_ready_to_issue' => $this->isReadyToIssue(),
            'missing_to_issue' => $this->missingToIssue(),
        ];
    }
}
