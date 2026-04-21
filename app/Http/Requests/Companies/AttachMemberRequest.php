<?php

declare(strict_types=1);

namespace App\Http\Requests\Companies;

use App\Enums\CompanyMemberRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AttachMemberRequest extends FormRequest
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
        $roles = array_map(fn (CompanyMemberRole $r) => $r->value, CompanyMemberRole::cases());

        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'role' => ['required', Rule::in($roles)],
            'job_title' => ['nullable', 'string', 'max:200'],
            'is_primary_contact' => ['sometimes', 'boolean'],
        ];
    }
}
