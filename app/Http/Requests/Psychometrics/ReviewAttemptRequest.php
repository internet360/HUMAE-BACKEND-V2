<?php

declare(strict_types=1);

namespace App\Http\Requests\Psychometrics;

use Illuminate\Foundation\Http\FormRequest;

class ReviewAttemptRequest extends FormRequest
{
    public function authorize(): bool
    {
        // La pertenencia y el rol los resuelve la Policy en el controller, que
        // necesita el perfil del candidato del intento.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Nullable a propósito: mandar vacío BORRA la interpretación, que es
            // una acción legítima si se escribió por error. `present` obliga a que
            // la llave venga, para que un payload incompleto no borre en silencio.
            'recommendations' => ['present', 'nullable', 'string', 'max:5000'],
        ];
    }
}
