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
 *
 * Y de QUIÉN es el cobro tiene que estar ahí también. Los `*_id` sueltos no lo
 * dicen: obligan a quien va a facturar a salir a buscar qué es la vacante 47
 * antes de poder hacer su trabajo. La moneda va por el mismo motivo — el
 * sistema se niega a asumirla al capturar el sueldo, así que tampoco puede
 * omitirla al mostrar el cargo.
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

            // Las tres relaciones se comprueban contra null aunque estén
            // cargadas: `whenLoaded` sólo dice que se hizo el eager load, no
            // que haya fila del otro lado. Un cargo cuya empresa se borró sigue
            // siendo facturable y no puede tumbar la lista entera.
            'company' => $this->whenLoaded('company', function (): ?array {
                $company = $this->company;

                if ($company === null) {
                    return null;
                }

                return [
                    'id' => $company->id,
                    // El comercial primero: es como el reclutador nombra al
                    // cliente en voz alta. La razón social es para el documento
                    // fiscal.
                    'name' => $company->trade_name ?? $company->legal_name,
                ];
            }),

            'vacancy' => $this->whenLoaded('vacancy', function (): ?array {
                $vacancy = $this->vacancy;

                if ($vacancy === null) {
                    return null;
                }

                return [
                    'id' => $vacancy->id,
                    'title' => $vacancy->title,
                    'code' => $vacancy->code,
                ];
            }),

            'currency' => $this->whenLoaded('currency', function (): ?array {
                $currency = $this->currency;

                if ($currency === null) {
                    return null;
                }

                return ['id' => $currency->id, 'code' => $currency->code];
            }),

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
