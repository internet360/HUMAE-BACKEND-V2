<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInvoiceRequestNotesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('invoices.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['admin_notes' => ['present', 'nullable', 'string', 'max:2000']];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['admin_notes' => 'notas'];
    }
}
