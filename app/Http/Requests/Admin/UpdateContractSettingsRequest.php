<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use App\Models\ContractSetting;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edición de los términos comerciales del contrato.
 *
 * Todos los campos son obligatorios a propósito: no es un PATCH parcial sino el
 * formulario completo del panel. Guardar la mitad de las condiciones dejaría un
 * contrato coherente a medias, y estos valores se imprimen en un documento que
 * obliga a pagar dinero.
 */
class UpdateContractSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->hasRole(UserRole::Admin->value);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'provider_name' => ['required', 'string', 'min:3', 'max:200'],

            // Sin apoderado el contrato saldría firmado por una sola parte.
            'signatory_name' => ['required', 'string', 'min:3', 'max:200'],
            'signatory_title' => ['required', 'string', 'min:2', 'max:200'],

            'fee_kind' => ['required', Rule::in(ContractSetting::FEE_KINDS)],
            'fee_value' => ['required', 'numeric', 'gt:0', 'max:9999999999'],

            // El monto en letra solo aplica —y solo se exige— con monto fijo.
            'fee_amount_words' => [
                Rule::requiredIf(fn (): bool => $this->input('fee_kind') === 'fixed_amount'),
                'nullable',
                'string',
                'max:200',
            ],

            'payment_days' => ['required', 'integer', 'min:1', 'max:365'],
            'payment_day_kind' => ['required', Rule::in(ContractSetting::PAYMENT_DAY_KINDS)],
            'warranty_days' => ['required', 'integer', 'min:1', 'max:3650'],

            'city' => ['nullable', 'string', 'max:200'],
            'jurisdiction' => ['required', 'string', 'min:3', 'max:300'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'signatory_name.required' => 'Indica quién firma por HUMAE: sin apoderado el contrato saldría firmado por una sola parte.',
            'signatory_title.required' => 'Indica el cargo del apoderado que firma por HUMAE.',
            'fee_value.gt' => 'Los honorarios deben ser mayores a cero.',
            'fee_amount_words.required' => 'Con monto fijo hay que escribir el importe en letra, como se acostumbra en un contrato.',
            'jurisdiction.required' => 'Indica el fuero al que se someten las partes.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'provider_name' => 'nombre del prestador',
            'signatory_name' => 'nombre del apoderado',
            'signatory_title' => 'cargo del apoderado',
            'fee_kind' => 'forma de honorarios',
            'fee_value' => 'monto de honorarios',
            'fee_amount_words' => 'importe en letra',
            'payment_days' => 'plazo de pago',
            'payment_day_kind' => 'tipo de días',
            'warranty_days' => 'días de garantía',
            'city' => 'ciudad de firma',
            'jurisdiction' => 'jurisdicción',
        ];
    }
}
