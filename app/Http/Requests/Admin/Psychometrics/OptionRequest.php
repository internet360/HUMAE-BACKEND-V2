<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Psychometrics;

use Illuminate\Foundation\Http\FormRequest;

class OptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('psychometric.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'label' => [$required, 'string', 'max:400'],
            'value' => [$required, 'string', 'max:80'],

            // Acá SÍ se acepta el puntaje: lo define el admin al construir el
            // instrumento, y es la fuente de verdad que después el candidato no
            // puede tocar. Con cota, para que un dedo pesado no meta un valor que
            // desbalancee todas las dimensiones.
            'score' => ['sometimes', 'integer', 'min:-100', 'max:100'],

            'is_correct' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
