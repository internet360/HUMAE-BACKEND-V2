<?php

declare(strict_types=1);

namespace App\Http\Requests\Pipeline;

use Illuminate\Foundation\Http\FormRequest;

class AssignmentNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'min:1', 'max:5000'],
            'visibility' => ['sometimes', 'in:internal,company,candidate'],
        ];
    }
}
