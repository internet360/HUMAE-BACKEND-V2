<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Catalogs;

use App\Models\Skill;
use Illuminate\Foundation\Http\FormRequest;

class SkillRequest extends FormRequest
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
        $routeSkill = $this->route('skill');
        $skillId = $routeSkill instanceof Skill ? $routeSkill->id : null;

        return [
            'code' => [
                $required,
                'string',
                'max:60',
                'regex:/^[a-z0-9_-]+$/',
                'unique:skills,code'.($skillId ? ','.$skillId : ''),
            ],
            'name' => [$required, 'string', 'max:120'],
            'category' => ['sometimes', 'nullable', 'string', 'in:tecnica,herramienta,blanda,idioma,otro'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
