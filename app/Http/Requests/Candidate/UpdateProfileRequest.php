<?php

declare(strict_types=1);

namespace App\Http\Requests\Candidate;

use App\Enums\CandidateKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
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
        $candidateKinds = array_map(fn (CandidateKind $k) => $k->value, CandidateKind::cases());

        return [
            // Identidad
            'first_name' => ['sometimes', 'required', 'string', 'min:1', 'max:120'],
            'last_name' => ['sometimes', 'required', 'string', 'min:1', 'max:120'],
            'headline' => ['sometimes', 'nullable', 'string', 'max:200'],
            'summary' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'birth_date' => ['sometimes', 'nullable', 'date', 'before:today'],
            'gender' => ['sometimes', 'nullable', 'in:male,female,other,prefer_not_to_say'],
            'curp' => ['sometimes', 'nullable', 'string', 'size:18'],
            'rfc' => ['sometimes', 'nullable', 'string', 'max:13'],

            // Contacto / redes
            'contact_email' => ['sometimes', 'nullable', 'email', 'max:160'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'whatsapp' => ['sometimes', 'nullable', 'string', 'max:30'],
            'linkedin_url' => ['sometimes', 'nullable', 'url', 'max:300'],
            'portfolio_url' => ['sometimes', 'nullable', 'url', 'max:300'],
            'github_url' => ['sometimes', 'nullable', 'url', 'max:300'],

            // Ubicación
            'country_id' => ['sometimes', 'nullable', 'integer', 'exists:countries,id'],
            'state_id' => ['sometimes', 'nullable', 'integer', 'exists:states,id'],
            'city_id' => ['sometimes', 'nullable', 'integer', 'exists:cities,id'],
            'address_line' => ['sometimes', 'nullable', 'string', 'max:300'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:15'],

            // Profesional
            'career_level_id' => ['sometimes', 'nullable', 'integer', 'exists:career_levels,id'],
            'functional_area_id' => ['sometimes', 'nullable', 'integer', 'exists:functional_areas,id'],
            'position_id' => ['sometimes', 'nullable', 'integer', 'exists:positions,id'],
            'candidate_kind' => ['sometimes', 'nullable', Rule::in($candidateKinds)],
            'other_area_text' => ['sometimes', 'nullable', 'string', 'max:200'],
            'functional_areas' => ['sometimes', 'array', 'max:10'],
            'functional_areas.*.id' => ['required_with:functional_areas', 'integer', 'exists:functional_areas,id'],
            'functional_areas.*.is_primary' => ['sometimes', 'boolean'],
            'years_of_experience' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:70'],

            // Salario
            'salary_currency_id' => ['sometimes', 'nullable', 'integer', 'exists:salary_currencies,id'],
            'expected_salary_min' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'expected_salary_max' => ['sometimes', 'nullable', 'numeric', 'gte:expected_salary_min'],
            'expected_salary_period' => ['sometimes', 'nullable', 'in:hora,dia,semana,quincena,mes,anio'],

            // Disponibilidad
            'availability' => ['sometimes', 'nullable', 'string', 'max:30'],
            'available_from' => ['sometimes', 'nullable', 'date'],
            'open_to_relocation' => ['sometimes', 'boolean'],
            'open_to_remote' => ['sometimes', 'boolean'],
        ];
    }
}
