<?php

declare(strict_types=1);

namespace App\Http\Requests\Companies;

use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;

class CompanyRequest extends FormRequest
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
        $routeCompany = $this->route('company');
        $companyId = $routeCompany instanceof Company ? $routeCompany->id : null;

        return [
            'legal_name' => [$required, 'string', 'max:200'],
            'trade_name' => ['sometimes', 'nullable', 'string', 'max:200'],
            'slug' => [
                'sometimes',
                'nullable',
                'string',
                'max:200',
                'alpha_dash',
                'unique:companies,slug'.($companyId ? ','.$companyId : ''),
            ],
            'rfc' => ['sometimes', 'nullable', 'string', 'max:13'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'website' => ['sometimes', 'nullable', 'url', 'max:300'],
            'logo_url' => ['sometimes', 'nullable', 'url', 'max:600'],
            'cover_url' => ['sometimes', 'nullable', 'url', 'max:600'],

            'industry_id' => ['sometimes', 'nullable', 'integer', 'exists:industries,id'],
            'company_size_id' => ['sometimes', 'nullable', 'integer', 'exists:company_sizes,id'],
            'ownership_type_id' => ['sometimes', 'nullable', 'integer', 'exists:ownership_types,id'],
            'founded_year' => ['sometimes', 'nullable', 'integer', 'min:1800', 'max:2099'],

            'contact_name' => ['sometimes', 'nullable', 'string', 'max:200'],
            'contact_email' => ['sometimes', 'nullable', 'email', 'max:160'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'contact_position' => ['sometimes', 'nullable', 'string', 'max:200'],

            'country_id' => ['sometimes', 'nullable', 'integer', 'exists:countries,id'],
            'state_id' => ['sometimes', 'nullable', 'integer', 'exists:states,id'],
            'city_id' => ['sometimes', 'nullable', 'integer', 'exists:cities,id'],
            'address_line' => ['sometimes', 'nullable', 'string', 'max:300'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:15'],

            'linkedin_url' => ['sometimes', 'nullable', 'url', 'max:300'],
            'facebook_url' => ['sometimes', 'nullable', 'url', 'max:300'],
            'instagram_url' => ['sometimes', 'nullable', 'url', 'max:300'],
            'twitter_url' => ['sometimes', 'nullable', 'url', 'max:300'],

            'status' => ['sometimes', 'in:active,paused,archived'],
            'internal_notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'account_manager_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
        ];
    }
}
