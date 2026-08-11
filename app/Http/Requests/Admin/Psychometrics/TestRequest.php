<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Psychometrics;

use App\Models\PsychometricTest;
use Illuminate\Foundation\Http\FormRequest;

class TestRequest extends FormRequest
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
        $routeTest = $this->route('test');
        $testId = $routeTest instanceof PsychometricTest ? $routeTest->id : null;

        return [
            'code' => [
                $required,
                'string',
                'max:80',
                'regex:/^[a-z0-9_-]+$/',
                'unique:psychometric_tests,code'.($testId ? ','.$testId : ''),
            ],
            'name' => [$required, 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'category' => ['sometimes', 'nullable', 'string', 'max:80'],
            'time_limit_minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:600'],
            'passing_score' => ['sometimes', 'nullable', 'integer', 'min:0'],

            // `null` es ilimitado y hay que poder declararlo explícitamente; por
            // eso `nullable` en lugar de un default silencioso.
            'max_attempts' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],

            'instructions' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'is_required' => ['sometimes', 'boolean'],
        ];
    }
}
