<?php

declare(strict_types=1);

namespace App\Http\Requests\Candidate;

use App\Rules\Rfc;
use App\Support\Sat\SatCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInvoiceRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $rfc = $this->input('rfc');

        if (is_string($rfc)) {
            $this->merge(['rfc' => mb_strtoupper(trim($rfc))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'rfc' => ['required', 'string', new Rfc],
            'legal_name' => ['required', 'string', 'min:1', 'max:300'],
            'tax_regime' => ['required', 'string', Rule::in(SatCatalog::regimeCodes())],
            'postal_code' => ['required', 'string', 'regex:/^\d{5}$/'],
            'cfdi_use' => ['required', 'string', Rule::in(SatCatalog::useCodes())],
            'email' => ['required', 'email:rfc', 'max:255'],
            'payment_ids' => ['required', 'array', 'min:1', 'max:50'],
            'payment_ids.*' => ['integer', 'distinct'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'rfc' => 'RFC',
            'legal_name' => 'razón social',
            'tax_regime' => 'régimen fiscal',
            'postal_code' => 'código postal',
            'cfdi_use' => 'uso de CFDI',
            'email' => 'correo electrónico',
            'payment_ids' => 'pagos',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'postal_code.regex' => 'El código postal debe tener 5 dígitos.',
            'tax_regime.in' => 'El régimen fiscal no es válido.',
            'cfdi_use.in' => 'El uso de CFDI no es válido.',
        ];
    }

    /**
     * @return array{rfc: string, legal_name: string, tax_regime: string, postal_code: string, cfdi_use: string, email: string}
     */
    public function fiscal(): array
    {
        /** @var array{rfc: string, legal_name: string, tax_regime: string, postal_code: string, cfdi_use: string, email: string} */
        return [
            ...$this->safe()->only(['rfc', 'legal_name', 'tax_regime', 'postal_code', 'cfdi_use', 'email']),
            'legal_name' => trim((string) $this->validated('legal_name')),
        ];
    }

    /** @return list<int> */
    public function paymentIds(): array
    {
        /** @var list<int> $ids */
        $ids = array_map('intval', (array) $this->validated('payment_ids'));

        return $ids;
    }
}
