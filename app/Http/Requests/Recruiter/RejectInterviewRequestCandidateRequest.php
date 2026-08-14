<?php

declare(strict_types=1);

namespace App\Http\Requests\Recruiter;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Veto de un perfil señalado por la empresa.
 *
 * El motivo es obligatorio y no por formalismo: lo lee el cliente. Un veto sin
 * explicación lo deja rehaciendo la selección a ciegas, probablemente hacia
 * alguien con el mismo impedimento.
 *
 * El mínimo de 10 caracteres es un piso contra el «no» seco, no una garantía de
 * calidad — eso lo da el criterio de quien escribe.
 */
class RejectInterviewRequestCandidateRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Explica por qué no se presentará este perfil: la empresa lo va a leer.',
            'reason.min' => 'El motivo es demasiado corto para que la empresa entienda qué pasó.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['reason' => 'motivo'];
    }
}
