<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Catalogs;

use App\Models\FunctionalArea;
use Illuminate\Foundation\Http\FormRequest;

class FunctionalAreaRequest extends FormRequest
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
        $routeFunctionalArea = $this->route('functionalArea');
        $functionalAreaId = $routeFunctionalArea instanceof FunctionalArea ? $routeFunctionalArea->id : null;

        return [
            'code' => [
                $required,
                'string',
                'max:60',
                'regex:/^[a-z0-9_-]+$/',
                'unique:functional_areas,code'.($functionalAreaId ? ','.$functionalAreaId : ''),
            ],
            'name' => [$required, 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
