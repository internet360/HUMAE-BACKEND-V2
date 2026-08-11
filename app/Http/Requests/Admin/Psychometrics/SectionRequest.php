<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Psychometrics;

use App\Models\PsychometricTest;
use App\Models\PsychometricTestSection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SectionRequest extends FormRequest
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

        $section = $this->route('section');
        $section = $section instanceof PsychometricTestSection ? $section : null;

        $testId = $this->resolveTestId($section);

        // `code` es único POR prueba (unique compuesto en la migración), no
        // global: dos pruebas pueden tener su propia sección "razonamiento".
        $unique = Rule::unique('psychometric_test_sections', 'code')
            ->where(fn ($query) => $query->where('psychometric_test_id', $testId));

        if ($section !== null) {
            $unique->ignore($section->id);
        }

        return [
            'code' => [$required, 'string', 'max:80', 'regex:/^[a-z0-9_-]+$/', $unique],
            'name' => [$required, 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'time_limit_minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:600'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    private function resolveTestId(?PsychometricTestSection $section): ?int
    {
        $routeTest = $this->route('test');

        if ($routeTest instanceof PsychometricTest) {
            return $routeTest->id;
        }

        return $section?->psychometric_test_id;
    }
}
