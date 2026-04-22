<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Catalogs;

use App\Models\Language;
use Illuminate\Foundation\Http\FormRequest;

class LanguageRequest extends FormRequest
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
        $routeLanguage = $this->route('language');
        $languageId = $routeLanguage instanceof Language ? $routeLanguage->id : null;

        return [
            'code' => [
                $required,
                'string',
                'max:20',
                'regex:/^[a-z0-9_-]+$/',
                'unique:languages,code'.($languageId ? ','.$languageId : ''),
            ],
            'name' => [$required, 'string', 'max:120'],
            'native_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
