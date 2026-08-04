<?php

declare(strict_types=1);

namespace App\Http\Requests\Companies;

use App\Http\Requests\Concerns\RestrictsFieldsByRole;
use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The one place that decides which company fields each role may write.
 *
 * Serves both the HUMAE registry (`POST/PATCH /companies[/{id}]`) and the
 * client's own profile (`PATCH /me/company`). `MyCompanyController` used to
 * carry its own copy of the field list, which is how the two surfaces drifted:
 * the company endpoint excluded HUMAE's commercial columns on purpose and the
 * staff endpoint handed the same user all of them (F-06).
 */
class CompanyRequest extends FormRequest
{
    use RestrictsFieldsByRole;

    /**
     * HUMAE's own columns on a client record: its lifecycle, who owns the
     * relationship, what HUMAE writes down about it, and the two identifiers
     * HUMAE issues (`slug`) or verifies (`rfc`).
     *
     * @return list<string>
     */
    protected function staffOnlyFields(): array
    {
        return [
            'status',
            'internal_notes',
            'account_manager_id',
            'rfc',
            'slug',
        ];
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
