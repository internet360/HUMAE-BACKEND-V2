<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Companies;

use App\Models\CompanyContract;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Estado del contrato de una empresa.
 *
 * No expone rutas de archivo ni la huella del PDF: el contrato se baja por el
 * endpoint autenticado, y la firma/INE/selfie no salen nunca de la API.
 *
 * @mixin CompanyContract
 */
class CompanyContractResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'folio' => $this->folio,
            'signed_at' => $this->signed_at->toIso8601String(),
            'signer' => [
                'name' => $this->whenLoaded('signedBy', fn () => $this->signedBy?->name),
                'title' => $this->signer_title,
            ],
            // El contrato vale con la firma; la constancia acredita la integridad
            // ante terceros. Se informan por separado porque el sello puede
            // llegar más tarde si CINCEL estaba caído.
            'is_timestamped' => $this->isTimestamped(),
            'timestamped_at' => $this->timestamped_at?->toIso8601String(),
            'terms' => [
                'version' => $this->terms['version'] ?? null,
                'fee_kind' => $this->terms['fee_kind'] ?? null,
                'fee_value' => $this->terms['fee_value'] ?? null,
                'warranty_days' => $this->terms['warranty_days'] ?? null,
                'payment_days' => $this->terms['payment_days'] ?? null,
            ],
            'download_url' => route('me.company.contract.download'),
        ];
    }
}
