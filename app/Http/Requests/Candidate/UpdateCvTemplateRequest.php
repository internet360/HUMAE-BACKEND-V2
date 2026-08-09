<?php

declare(strict_types=1);

namespace App\Http\Requests\Candidate;

use App\Enums\CvTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateCvTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'template' => ['required', new Enum(CvTemplate::class)],
        ];
    }
}
