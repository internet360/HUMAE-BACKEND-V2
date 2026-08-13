<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Pipeline;

use App\Models\PlacementCharge;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PlacementCharge
 *
 * Un cargo por colocación devengado.
 *
 * Expone el desglose completo —forma de honorarios, valor, sueldo con su
 * período, base anual y monto— y no sólo el total. Un cliente que objeta la
 * factura pregunta cómo salió el número, y la respuesta tiene que estar en el
 * mismo lugar que el número.
 */
class PlacementChargeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'state' => $this->state?->value,
            'state_label' => $this->state?->label(),

            'company_id' => $this->company_id,
            'vacancy_id' => $this->vacancy_id,
            'vacancy_assignment_id' => $this->vacancy_assignment_id,
            'company_contract_id' => $this->company_contract_id,

            'fee_source' => $this->fee_source,
            'fee_kind' => $this->fee_kind,
            'fee_value' => (float) $this->fee_value,

            'final_salary_amount' => (float) $this->final_salary_amount,
            'final_salary_period' => $this->final_salary_period,
            'annual_base' => (float) $this->annual_base,
            'amount' => (float) $this->amount,
            'salary_currency_id' => $this->salary_currency_id,

            'salary_confirmed_by_user_id' => $this->salary_confirmed_by_user_id,
            'accrued_by_user_id' => $this->accrued_by_user_id,
            'accrued_at' => $this->accrued_at?->toIso8601String(),
        ];
    }
}
