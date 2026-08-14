<?php

declare(strict_types=1);

namespace App\Http\Requests\Recruiter;

use App\Services\PlacementChargeService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Captura del sueldo final confirmado de una colocación.
 *
 * Es la base del cargo por colocación, así que los tres datos son obligatorios y
 * ninguno tiene default. «38,000» sin período no significa nada, y sin moneda
 * tampoco: un cargo del 12% sobre 38,000 pesos y sobre 38,000 dólares no son el
 * mismo cobro.
 *
 * Los períodos aceptados los dicta `PlacementChargeService`: sólo aquellos que
 * se pueden anualizar sin inventar una jornada. Ver su constante `ANNUAL_FACTOR`.
 */
class ConfirmFinalSalaryRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'final_salary_amount' => ['required', 'numeric', 'min:1'],
            'final_salary_period' => ['required', Rule::in(PlacementChargeService::supportedPeriodValues())],
            'final_salary_currency_id' => ['required', 'integer', 'exists:salary_currencies,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'final_salary_period.in' => 'Registra el sueldo por semana, quincena, mes o año: por hora o por día no se puede anualizar sin conocer la jornada.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'final_salary_amount' => 'sueldo final',
            'final_salary_period' => 'período del sueldo',
            'final_salary_currency_id' => 'moneda',
        ];
    }
}
