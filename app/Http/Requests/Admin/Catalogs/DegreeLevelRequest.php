<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Catalogs;

use App\Models\DegreeLevel;
use Illuminate\Foundation\Http\FormRequest;

class DegreeLevelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('catalogs.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';
        $routeDegreeLevel = $this->route('degreeLevel');
        $degreeLevelId = $routeDegreeLevel instanceof DegreeLevel ? $routeDegreeLevel->id : null;

        return [
            'code' => [
                $required,
                'string',
                'max:60',
                'regex:/^[a-z0-9_-]+$/',
                'unique:degree_levels,code'.($degreeLevelId ? ','.$degreeLevelId : ''),
            ],
            'name' => [$required, 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
