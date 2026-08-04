<?php

declare(strict_types=1);

namespace App\Http\Requests\Shared;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Public, unauthenticated lead-capture form: the marketing site's contact
 * form and the "solicitar acceso" page for client companies (ARCHITECTURE.md
 * §6 makes company accounts invitation-only, so this is how a prospective
 * client reaches HUMAE instead of self-registering).
 */
class CreateContactSubmissionRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:200'],
            'email' => ['required', 'email', 'max:160'],
            'phone' => ['nullable', 'string', 'max:30'],
            'company' => ['nullable', 'string', 'max:200'],
            'subject' => ['nullable', 'string', 'max:300'],
            'message' => ['required', 'string', 'max:5000'],
            // Matches the value sets documented as column comments on the
            // `contact_submissions` migration; keeping the request in sync
            // with the schema instead of letting free text drift from it.
            'type' => ['nullable', 'string', Rule::in(['contact', 'company_request', 'support'])],
            'source' => ['nullable', 'string', Rule::in(['landing', 'contacto', 'empresas'])],
        ];
    }
}
