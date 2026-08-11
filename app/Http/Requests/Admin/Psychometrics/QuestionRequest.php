<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Psychometrics;

use App\Enums\QuestionType;
use App\Models\PsychometricQuestion;
use App\Models\PsychometricTest;
use App\Models\PsychometricTestSection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class QuestionRequest extends FormRequest
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
            'section_id' => ['sometimes', 'nullable', 'integer'],
            // Contra los tipos SOPORTADOS, no contra el enum completo: `rank`
            // existe como caso pero el scoring no lo califica, así que un ítem así
            // valdría 0 en silencio. Ver `QuestionType::supported()`.
            'type' => [$required, Rule::in(QuestionType::supportedValues())],
            'prompt' => [$required, 'string', 'max:5000'],
            'image_url' => ['sometimes', 'nullable', 'string', 'max:600', 'url'],

            // La dimensión es la llave con la que el scoring agrupa. Se restringe
            // el formato para que "Extraversión" y "extraversion" no terminen
            // siendo dos dimensiones distintas en el mismo resultado.
            'dimension' => ['sometimes', 'nullable', 'string', 'max:80', 'regex:/^[a-z0-9_-]+$/'],

            'weight' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'is_reverse_scored' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.in' => 'Ese tipo de ítem todavía no se puede calificar, así que no se puede crear.',
        ];
    }

    /**
     * La sección tiene que ser de la MISMA prueba que la pregunta.
     *
     * Sin esto se puede colgar una pregunta de la sección de otra prueba: el
     * cuestionario queda con una sección que no le pertenece y el candidato ve
     * ítems agrupados bajo un título ajeno.
     *
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $sectionId = $this->input('section_id');

                if ($sectionId === null || $sectionId === '') {
                    return;
                }

                $test = $this->resolveTest();

                if ($test === null) {
                    return;
                }

                $belongs = PsychometricTestSection::query()
                    ->where('id', (int) $sectionId)
                    ->where('psychometric_test_id', $test->id)
                    ->exists();

                if (! $belongs) {
                    $validator->errors()->add(
                        'section_id',
                        'La sección no pertenece a esta prueba.',
                    );
                }
            },
        ];
    }

    /**
     * En `store` la prueba viene por la ruta; en `update` hay que subir desde la
     * pregunta.
     */
    private function resolveTest(): ?PsychometricTest
    {
        $routeTest = $this->route('test');

        if ($routeTest instanceof PsychometricTest) {
            return $routeTest;
        }

        $question = $this->route('question');

        return $question instanceof PsychometricQuestion ? $question->test : null;
    }
}
