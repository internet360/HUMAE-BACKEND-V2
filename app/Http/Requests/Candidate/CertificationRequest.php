<?php

declare(strict_types=1);

namespace App\Http\Requests\Candidate;

use Illuminate\Foundation\Http\FormRequest;

class CertificationRequest extends FormRequest
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
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:200'],
            'issuer' => [$required, 'string', 'max:200'],
            'credential_id' => ['sometimes', 'nullable', 'string', 'max:120'],
            'credential_url' => ['sometimes', 'nullable', 'url', 'max:500'],
            'issued_at' => ['sometimes', 'nullable', 'date'],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:issued_at'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
