<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Datos del usuario solicitante (será el "owner" de la empresa).
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email', 'max:160', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
            'accept_terms' => ['required', 'accepted'],

            // Datos de la empresa.
            'company.legal_name' => ['required', 'string', 'min:2', 'max:200'],
            'company.trade_name' => ['nullable', 'string', 'max:200'],
            'company.rfc' => ['nullable', 'string', 'max:30'],
            'company.website' => ['nullable', 'url', 'max:200'],
            'company.contact_name' => ['nullable', 'string', 'max:120'],
            'company.contact_email' => ['nullable', 'email', 'max:160'],
            'company.contact_phone' => ['nullable', 'string', 'max:30'],
            'company.industry_id' => ['nullable', 'integer', 'exists:industries,id'],
            'company.company_size_id' => ['nullable', 'integer', 'exists:company_sizes,id'],
            'company.motivo' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'accept_terms.accepted' => 'Debes aceptar los términos y condiciones.',
            'password.confirmed' => 'La confirmación de contraseña no coincide.',
            'company.legal_name.required' => 'El nombre legal de la empresa es obligatorio.',
        ];
    }
}
